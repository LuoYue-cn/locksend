<?php

declare(strict_types=1);

namespace LockSend\Tests;

use LockSend\Support\Base64Url;
use PHPUnit\Framework\TestCase;

final class FileSizeLimitTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function body(int $chunks): array
    {
        return [
            'kdf_v' => 1, 'salt' => Base64Url::encode(random_bytes(16)), 'auth' => Base64Url::encode(random_bytes(32)),
            'enc_meta' => Base64Url::encode(random_bytes(60)), 'sealed_name' => Base64Url::encode("\x04" . random_bytes(100)),
            'chunk_count' => $chunks, 'max_fails' => 5, 'fail_action' => 'destroy', 'ttl_days' => 7,
            'burn_after_complete' => true, 'max_completions' => 1,
        ];
    }

    private function create(Harness $h, int $chunks): \LockSend\Support\Response
    {
        $d = Harness::data($h->call('POST', '/api/shares/reserve'));
        return $h->call('POST', "/api/shares/{$d['share_id']}", self::body($chunks), $d['upload_token']);
    }

    public function testDefaultLimitIs500MB(): void
    {
        $h = new Harness();
        $cfg = Harness::data($h->call('GET', '/api/config'));
        $this->assertSame(500000000, $cfg['max_file_bytes']);
        // 500 000 000 / 4 194 304 = 119.2 -> 120 chunks is the most a 500 MB file can need
        $this->assertSame(120, $h->app->settings()->maxChunkCount());
    }

    public function testUploadAtTheLimitIsAccepted(): void
    {
        $this->assertSame(201, $this->create(new Harness(), 120)->status);
    }

    public function testUploadAboveTheLimitIsRefusedWith413(): void
    {
        $h = new Harness();
        $r = $this->create($h, 121);
        $this->assertSame(413, $r->status);
        $this->assertSame('file_too_large', Harness::data($r)['error']);
        $this->assertSame(500000000, Harness::data($r)['max_file_bytes']);
        $this->assertSame(413, $this->create($h, 16777216)->status);
        $this->assertSame(0, (int) $h->app->db()->one('SELECT COUNT(*) AS n FROM shares')['n'], 'nothing is stored');
    }

    public function testLimitIsConfigurable(): void
    {
        $h = new Harness(true, ['LOCKSEND_MAX_FILE_BYTES' => (string) (10 * 4194304)]);
        $this->assertSame(10, $h->app->settings()->maxChunkCount());
        $this->assertSame(201, $this->create($h, 10)->status);
        $this->assertSame(413, $this->create($h, 11)->status);
    }

    public function testChunksBeyondTheDeclaredCountAreRejected(): void
    {
        $h = new Harness();
        $d = Harness::data($h->call('POST', '/api/shares/reserve'));
        $h->call('POST', "/api/shares/{$d['share_id']}", self::body(2), $d['upload_token']);
        $r = $h->call('PUT', "/api/shares/{$d['share_id']}/chunks/2", random_bytes(40), $d['upload_token']);
        $this->assertSame(400, $r->status, 'a client cannot exceed the limit by sending more chunks than declared');
    }
}
