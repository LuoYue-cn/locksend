<?php

declare(strict_types=1);

namespace LockSend\Tests;

use LockSend\Support\Base64Url;
use LockSend\Support\Config;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    private Harness $h;

    protected function setUp(): void
    {
        $this->h = new Harness();
    }

    /** @return array{0:string,1:array<string,mixed>} download token and unlock payload */
    private function unlock(array $s): array
    {
        $r = $this->h->call('POST', "/api/shares/{$s['id']}/unlock", ['auth' => Base64Url::encode($s['auth'])]);
        $this->assertSame(200, $r->status, $r->body);
        $d = Harness::data($r);
        return [$d['download_token'], $d];
    }

    private function wrongUnlock(string $id): \LockSend\Support\Response
    {
        return $this->h->call('POST', "/api/shares/$id/unlock", ['auth' => Base64Url::encode(random_bytes(32))]);
    }

    public function testConfigServesFixedKdfParams(): void
    {
        $d = Harness::data($this->h->call('GET', '/api/config'));
        $this->assertSame(['v' => 1, 'm' => 65536, 't' => 3, 'p' => 1, 'len' => 32], $d['kdf']);
        $this->assertSame(7, $d['ttl_days']['default']);
        $this->assertSame(30, $d['ttl_days']['max']);
        $this->assertSame($this->h->adminPub, $d['admin_pub']);
    }

    public function testShareIdIs22CharBase64Url(): void
    {
        $d = Harness::data($this->h->call('POST', '/api/shares/reserve'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $d['share_id']);
        $this->assertSame(16, strlen(Base64Url::decode($d['share_id'])));
    }

    public function testFullLifecycleAndBurnAfterComplete(): void
    {
        $s = $this->h->uploadShare(2);
        [$token] = $this->unlock($s);
        $id = $s['id'];

        // completion before all chunks were served is refused
        $this->assertSame(409, $this->h->call('POST', "/api/shares/$id/complete", null, $token)->status);
        foreach ([0, 1] as $i) {
            $r = $this->h->call('GET', "/api/shares/$id/chunks/$i", null, $token);
            $this->assertSame(200, $r->status);
            $this->assertSame((string) ($i === 0 ? Config::CCHUNK : 40), $r->headers['Content-Length']);
        }
        $done = $this->h->call('POST', "/api/shares/$id/complete", null, $token);
        $this->assertSame(['burned' => true], Harness::data($done));

        $row = $this->h->app->shares()->find($id);
        $this->assertSame('burned', $row['status']);
        $this->assertSame('', $row['enc_meta']);
        $this->assertSame('', $row['sealed_name']);
        $this->assertSame('', $row['auth_verifier']);
        $this->assertDirectoryDoesNotExist($this->h->config->blobsDir() . "/$id");
        $this->assertSame(410, $this->h->call('GET', "/api/shares/$id/challenge")->status);
    }

    public function testMaxCompletionsAllowsSeveralDownloads(): void
    {
        $s = $this->h->uploadShare(1, ['max_completions' => 2]);
        foreach ([false, true] as $expectBurn) {
            [$t] = $this->unlock($s);
            $this->h->call('GET', "/api/shares/{$s['id']}/chunks/0", null, $t);
            $r = $this->h->call('POST', "/api/shares/{$s['id']}/complete", null, $t);
            $this->assertSame($expectBurn, Harness::data($r)['burned']);
        }
    }

    public function testNoBurnKeepsShareReadyAfterDownload(): void
    {
        $s = $this->h->uploadShare(1, ['burn_after_complete' => false]);
        [$t] = $this->unlock($s);
        $this->h->call('GET', "/api/shares/{$s['id']}/chunks/0", null, $t);
        $this->assertFalse(Harness::data($this->h->call('POST', "/api/shares/{$s['id']}/complete", null, $t))['burned']);
        $this->assertSame('ready', $this->h->app->shares()->find($s['id'])['status']);
    }

    public function testWrongCodesDestroyAtMaxFails(): void
    {
        $s = $this->h->uploadShare(1, ['max_fails' => 3]);
        $r1 = $this->wrongUnlock($s['id']);
        $this->assertSame(401, $r1->status);
        $this->assertSame(2, Harness::data($r1)['attempts_left']);
        $this->assertSame(1, Harness::data($this->wrongUnlock($s['id']))['attempts_left']);
        $r3 = $this->wrongUnlock($s['id']);
        $this->assertSame(410, $r3->status);
        $this->assertSame('destroyed', Harness::data($r3)['error']);
        $this->assertSame('destroyed', $this->h->app->shares()->find($s['id'])['status']);
        $this->assertDirectoryDoesNotExist($this->h->config->blobsDir() . "/{$s['id']}");

        // even the correct code no longer works
        $this->assertSame(410, $this->h->call('POST', "/api/shares/{$s['id']}/unlock", ['auth' => Base64Url::encode($s['auth'])])->status);
    }

    public function testCorrectCodeResetsFailCounter(): void
    {
        $s = $this->h->uploadShare(1, ['max_fails' => 3]);
        $this->wrongUnlock($s['id']);
        $this->wrongUnlock($s['id']);
        $this->unlock($s);
        $this->assertSame(2, Harness::data($this->wrongUnlock($s['id']))['attempts_left']);
    }

    public function testFreezePolicyLocksFor24hThenRecovers(): void
    {
        $s = $this->h->uploadShare(1, ['max_fails' => 3, 'fail_action' => 'freeze_24h']);
        $this->wrongUnlock($s['id']);
        $this->wrongUnlock($s['id']);
        $r = $this->wrongUnlock($s['id']);
        $this->assertSame(423, $r->status);
        $this->assertSame('86400', $r->headers['Retry-After']);
        // correct code is also refused while frozen
        $this->assertSame(423, $this->h->call('POST', "/api/shares/{$s['id']}/unlock", ['auth' => Base64Url::encode($s['auth'])])->status);
        $this->h->clock->t += 86401;
        $this->unlock($s);
    }

    public function testExpiredShareIsGone(): void
    {
        $s = $this->h->uploadShare(1);
        $this->h->clock->t += 8 * 86400;
        $this->assertSame(410, $this->h->call('GET', "/api/shares/{$s['id']}/challenge")->status);
        $this->assertSame(410, $this->wrongUnlock($s['id'])->status);
        $this->assertSame('expired', $this->h->app->shares()->find($s['id'])['status']);
    }

    public function testUnknownAndClosedSharesLookIdentical(): void
    {
        $unknown = $this->h->call('GET', '/api/shares/' . Base64Url::encode(random_bytes(16)) . '/challenge');
        $s = $this->h->uploadShare(1);
        $this->h->app->shares()->close($s['id'], 'destroyed');
        $closed = $this->h->call('GET', "/api/shares/{$s['id']}/challenge");
        $this->assertSame($unknown->status, $closed->status);
        $this->assertSame($unknown->body, $closed->body);
    }

    public function testChallengeRevealsOnlySaltAndFixedParams(): void
    {
        $s = $this->h->uploadShare(1);
        $d = Harness::data($this->h->call('GET', "/api/shares/{$s['id']}/challenge"));
        $this->assertSame(['kdf', 'salt'], array_keys($d) === ['salt', 'kdf'] ? ['kdf', 'salt'] : array_keys($d));
    }

    public function testChunkEndpointNeedsTokenForThatShare(): void
    {
        $a = $this->h->uploadShare(1);
        $b = $this->h->uploadShare(1);
        [$ta] = $this->unlock($a);
        $this->assertSame(401, $this->h->call('GET', "/api/shares/{$a['id']}/chunks/0")->status);
        $this->assertSame(401, $this->h->call('GET', "/api/shares/{$b['id']}/chunks/0", null, $ta)->status);
        $this->assertSame(200, $this->h->call('GET', "/api/shares/{$a['id']}/chunks/0", null, $ta)->status);
    }

    public function testUploadValidation(): void
    {
        $d = Harness::data($this->h->call('POST', '/api/shares/reserve'));
        $base = [
            'kdf_v' => 1, 'salt' => Base64Url::encode(random_bytes(16)), 'auth' => Base64Url::encode(random_bytes(32)),
            'enc_meta' => Base64Url::encode(random_bytes(60)), 'sealed_name' => Base64Url::encode("\x04" . random_bytes(100)),
            'chunk_count' => 1, 'max_fails' => 5, 'fail_action' => 'destroy', 'ttl_days' => 7,
            'burn_after_complete' => true, 'max_completions' => 1,
        ];
        $bad = [
            ['ttl_days' => 31], ['ttl_days' => 0], ['max_fails' => 4], ['fail_action' => 'ignore'], ['kdf_v' => 2],
            ['salt' => Base64Url::encode(random_bytes(8))], ['chunk_count' => 0], ['burn_after_complete' => 1],
            ['sealed_name' => Base64Url::encode(random_bytes(100))],
        ];
        foreach ($bad as $patch) {
            $r = $this->h->call('POST', "/api/shares/{$d['share_id']}", $patch + $base, $d['upload_token']);
            $this->assertSame(400, $r->status, json_encode($patch));
        }
        $this->assertSame(401, $this->h->call('POST', "/api/shares/{$d['share_id']}", $base)->status);
        $this->assertSame(201, $this->h->call('POST', "/api/shares/{$d['share_id']}", $base, $d['upload_token'])->status);
        $this->assertSame(409, $this->h->call('POST', "/api/shares/{$d['share_id']}", $base, $d['upload_token'])->status);
    }

    public function testChunkSizeRulesAndFinalizeCompleteness(): void
    {
        $d = Harness::data($this->h->call('POST', '/api/shares/reserve'));
        $id = $d['share_id'];
        $t = $d['upload_token'];
        $this->h->call('POST', "/api/shares/$id", [
            'kdf_v' => 1, 'salt' => Base64Url::encode(random_bytes(16)), 'auth' => Base64Url::encode(random_bytes(32)),
            'enc_meta' => Base64Url::encode(random_bytes(60)), 'sealed_name' => Base64Url::encode("\x04" . random_bytes(100)),
            'chunk_count' => 2, 'max_fails' => 5, 'fail_action' => 'destroy', 'ttl_days' => 7,
            'burn_after_complete' => true, 'max_completions' => 1,
        ], $t);
        $this->assertSame(400, $this->h->call('PUT', "/api/shares/$id/chunks/0", random_bytes(100), $t)->status, 'non-final chunk must be CCHUNK');
        $this->assertSame(400, $this->h->call('PUT', "/api/shares/$id/chunks/2", random_bytes(40), $t)->status, 'index out of range');
        $this->assertSame(400, $this->h->call('PUT', "/api/shares/$id/chunks/1", random_bytes(8), $t)->status, 'shorter than a tag');
        $this->assertSame(204, $this->h->call('PUT', "/api/shares/$id/chunks/1", random_bytes(16), $t)->status);
        $f = $this->h->call('POST', "/api/shares/$id/finalize", null, $t);
        $this->assertSame(409, $f->status);
        $this->assertSame(0, Harness::data($f)['missing']);
        $this->assertSame(204, $this->h->call('PUT', "/api/shares/$id/chunks/0", random_bytes(Config::CCHUNK), $t)->status);
        $st = Harness::data($this->h->call('GET', "/api/shares/$id/upload-status", null, $t));
        $this->assertSame([[0, 1]], $st['received']);
        $this->assertSame(200, $this->h->call('POST', "/api/shares/$id/finalize", null, $t)->status);
        // upload token is single use after finalisation
        $this->assertSame(401, $this->h->call('PUT', "/api/shares/$id/chunks/0", random_bytes(Config::CCHUNK), $t)->status);
    }

    public function testAbortDeletesUpload(): void
    {
        $d = Harness::data($this->h->call('POST', '/api/shares/reserve'));
        $this->assertSame(401, $this->h->call('DELETE', "/api/shares/{$d['share_id']}")->status);
        $this->assertSame(204, $this->h->call('DELETE', "/api/shares/{$d['share_id']}", null, $d['upload_token'])->status);
    }

    public function testReserveRateLimit(): void
    {
        $limit = $this->h->app->settings()->createPerHour();
        for ($i = 0; $i < $limit; $i++) {
            $this->assertSame(201, $this->h->call('POST', '/api/shares/reserve')->status);
        }
        $this->assertSame(429, $this->h->call('POST', '/api/shares/reserve')->status);
        $this->assertSame(201, $this->h->call('POST', '/api/shares/reserve', null, null, [], [], '198.51.100.7')->status);
    }

    public function testUnknownRoutes(): void
    {
        $this->assertSame(404, $this->h->call('GET', '/api/nope')->status);
        $this->assertSame(405, $this->h->call('GET', '/api/shares/reserve')->status);
        $this->assertSame(404, $this->h->call('GET', '/other')->status);
    }

    public function testResponsesCarryNoStoreAndNosniff(): void
    {
        $r = $this->h->call('GET', '/api/config');
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
    }

    public function testDatabaseHoldsNoPlaintextOrCodeMaterial(): void
    {
        $s = $this->h->uploadShare(1);
        $row = $this->h->app->shares()->find($s['id']);
        $this->assertNotSame(Base64Url::encode($s['auth']), $row['auth_verifier']);
        $this->assertSame(64, strlen($row['auth_verifier']));
        $this->assertSame(hash_hmac('sha256', $s['auth'], str_repeat("\xab", 32)), $row['auth_verifier']);
    }
}
