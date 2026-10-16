<?php

declare(strict_types=1);

namespace LockSend\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Recomputes the browser-generated vectors (web/tests/vectors.json) with libsodium,
 * hash_hkdf and OpenSSL. Agreement shows the JS protocol code matches independent implementations.
 */
final class VectorsTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $v;

    protected function setUp(): void
    {
        $file = dirname(__DIR__, 2) . '/web/tests/vectors.json';
        $this->v = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    }

    private function kdf(): array
    {
        $v = $this->v;
        $code = trim(mb_strtolower(\Normalizer::normalize($v['code_input'], \Normalizer::FORM_KC)));
        $code = preg_replace('/[\s_]+/', '-', $code);
        $this->assertSame($v['code_normalized'], $code);
        $m = sodium_crypto_pwhash(
            $v['kdf']['len'],
            $code,
            hex2bin($v['salt_hex']),
            $v['kdf']['t'],
            $v['kdf']['m'] * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        $ikm = $m . hex2bin($v['k_link_hex']);
        $id = hex2bin($v['share_id_hex']);
        $kFile = hash_hkdf('sha256', $ikm, 32, 'file-master-v1', $id);
        return [
            'auth' => hash_hkdf('sha256', $ikm, 32, 'share-auth-v1', $id),
            'k_data' => hash_hkdf('sha256', $kFile, 32, 'data-v1', ''),
            'k_meta' => hash_hkdf('sha256', $kFile, 32, 'meta-v1', ''),
        ];
    }

    public function testKeyDerivation(): void
    {
        $k = $this->kdf();
        $this->assertSame($this->v['auth_hex'], bin2hex($k['auth']));
        $this->assertSame($this->v['k_data_hex'], bin2hex($k['k_data']));
        $this->assertSame($this->v['k_meta_hex'], bin2hex($k['k_meta']));
    }

    public function testHkdfRfc5869Case1(): void
    {
        $c = $this->v['hkdf_rfc5869_case1'];
        $okm = hash_hkdf('sha256', hex2bin($c['ikm_hex']), 42, hex2bin($c['info_hex']), hex2bin($c['salt_hex']));
        $this->assertSame($c['okm_hex'], bin2hex($okm));
    }

    /** @return array{0:string,1:string} */
    private function gcm(string $key, string $iv, string $plain, string $aad): array
    {
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        $this->assertNotFalse($ct);
        return [$ct, $tag];
    }

    public function testChunkVectors(): void
    {
        $k = $this->kdf()['k_data'];
        foreach (['chunk0', 'chunk1', 'empty_file'] as $name) {
            $c = $this->v[$name];
            [$ct, $tag] = $this->gcm($k, hex2bin($c['iv_hex']), hex2bin($c['plain_hex'] ?? ''), hex2bin($c['aad_hex']));
            $this->assertSame($c['cipher_hex'], bin2hex($ct . $tag), $name);
        }
    }

    public function testIvAndAadLayout(): void
    {
        $id = hex2bin($this->v['share_id_hex']);
        $this->assertSame($this->v['chunk1']['aad_hex'], bin2hex('v1' . $id . pack('N', 1) . "\x01"));
        $this->assertSame($this->v['iv_index_2_pow_32_plus_2_hex'], bin2hex(pack('N', 0) . pack('J', 0x100000002)));
    }

    public function testMetadataVector(): void
    {
        $k = $this->kdf()['k_meta'];
        $iv = hex2bin($this->v['meta_iv_hex']);
        [$ct, $tag] = $this->gcm($k, $iv, $this->v['meta_json'], 'meta-v1' . hex2bin($this->v['share_id_hex']));
        $this->assertSame($this->v['enc_meta_hex'], bin2hex($iv . $ct . $tag));
    }
}
