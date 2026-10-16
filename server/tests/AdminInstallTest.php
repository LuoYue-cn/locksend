<?php

declare(strict_types=1);

namespace LockSend\Tests;

use LockSend\Support\Base64Url;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

final class AdminInstallTest extends TestCase
{
    private function freshInstall(): array
    {
        $h = new Harness(false);
        $h->config->ensureDirs();
        $token = Base64Url::encode(random_bytes(24));
        file_put_contents($h->config->setupTokenFile(), hash('sha256', $token) . "\n");
        return [$h, $token];
    }

    private function finishBody(string $token, string $code, string $login): array
    {
        return [
            'setup_token' => $token,
            'admin_pub' => Base64Url::encode("\x04" . random_bytes(64)),
            'wrap_salt' => Base64Url::encode(random_bytes(16)),
            'wrapped_priv' => Base64Url::encode(random_bytes(100)),
            'login_secret' => $login,
            'totp_code' => $code,
        ];
    }

    public function testInstallFlowAndLockout(): void
    {
        [$h, $token] = $this->freshInstall();
        $st = Harness::data($h->call('GET', '/api/install/status'));
        $this->assertFalse($st['installed']);
        $this->assertTrue($st['checks']['sodium']);

        $this->assertSame(503, $h->call('POST', '/api/shares/reserve')->status, 'uploads blocked before install');
        $this->assertSame(403, $h->call('POST', '/api/install/begin', ['setup_token' => str_repeat('A', 32)])->status);

        $begin = Harness::data($h->call('POST', '/api/install/begin', ['setup_token' => $token]));
        $this->assertNotEmpty($begin['totp_secret']);
        $login = Base64Url::encode(random_bytes(32));

        $this->assertSame(401, $h->call('POST', '/api/install/finish', $this->finishBody($token, '000000', $login))->status);
        $code = TOTP::createFromSecret($begin['totp_secret'])->now();
        $r = $h->call('POST', '/api/install/finish', $this->finishBody($token, $code, $login));
        $this->assertSame(201, $r->status, $r->body);

        $this->assertFileExists($h->config->installLockFile());
        $this->assertFileDoesNotExist($h->config->setupTokenFile());
        // install endpoints are sealed afterwards
        $this->assertSame(200, $h->call('GET', '/api/install/status')->status);
        $this->assertSame(['installed' => true], Harness::data($h->call('GET', '/api/install/status')));
        $this->assertSame(404, $h->call('POST', '/api/install/begin', ['setup_token' => $token])->status);
        $this->assertSame(404, $h->call('POST', '/api/install/finish', $this->finishBody($token, $code, $login))->status);
        $this->assertSame(201, $h->call('POST', '/api/shares/reserve')->status, 'uploads work after install');
    }

    public function testFinishRequiresBegin(): void
    {
        [$h, $token] = $this->freshInstall();
        $r = $h->call('POST', '/api/install/finish', $this->finishBody($token, '123456', Base64Url::encode(random_bytes(32))));
        $this->assertSame(409, $r->status);
    }

    public function testInstallRefusedWithoutSetupToken(): void
    {
        $h = new Harness(false);
        $this->assertSame(403, $h->call('POST', '/api/install/begin', ['setup_token' => str_repeat('A', 32)])->status);
    }

    public function testBadSetupTokenIsRateLimited(): void
    {
        [$h] = $this->freshInstall();
        $codes = [];
        for ($i = 0; $i < 14; $i++) {
            $codes[] = $h->call('POST', '/api/install/begin', ['setup_token' => Base64Url::encode(random_bytes(24))])->status;
        }
        $this->assertContains(429, $codes);
    }

    /** @return array{0:Harness,1:string,2:string,3:string} */
    private function adminHarness(): array
    {
        $h = new Harness(false);
        $h->config->ensureDirs();
        (new \LockSend\Support\Migrator($h->app->db(), dirname(__DIR__) . '/migrations'))->run();
        $gen = $h->app->totp()->generate('admin');
        $login = random_bytes(32);
        $h->app->admins()->save(Base64Url::encode("\x04" . random_bytes(64)), Base64Url::encode(random_bytes(80)), Base64Url::encode(random_bytes(16)), $h->app->shareAuth()->verifier($login), $gen['stored']);
        file_put_contents($h->config->installLockFile(), '1');
        return [$h, $gen['secret'], Base64Url::encode($login), $gen['stored']];
    }

    public function testAdminLoginListDeleteAndNoLeakedFields(): void
    {
        [$h, $secret, $login] = $this->adminHarness();
        $s = $h->uploadShare(1);

        $this->assertSame(401, $h->call('GET', '/api/admin/shares')->status);
        $bad = $h->call('POST', '/api/admin/login', ['login_secret' => $login, 'totp_code' => '000000']);
        $this->assertSame(401, $bad->status);
        $wrongPw = $h->call('POST', '/api/admin/login', ['login_secret' => Base64Url::encode(random_bytes(32)), 'totp_code' => TOTP::createFromSecret($secret)->now()]);
        $this->assertSame(401, $wrongPw->status);

        $ok = $h->call('POST', '/api/admin/login', ['login_secret' => $login, 'totp_code' => TOTP::createFromSecret($secret)->now()]);
        $this->assertSame(200, $ok->status, $ok->body);
        [$name, $val] = [$ok->cookies[0][0], $ok->cookies[0][1]];
        $cookies = [$name => $val];

        $list = Harness::data($h->call('GET', '/api/admin/shares', null, null, [], $cookies));
        $this->assertCount(1, $list['shares']);
        $this->assertSame(['id', 'sealed_name', 'created_at', 'expires_at'], array_keys($list['shares'][0]));

        $this->assertSame(403, $h->call('DELETE', "/api/admin/shares/{$s['id']}", null, null, [], $cookies)->status, 'CSRF header required');
        $this->assertSame(204, $h->call('DELETE', "/api/admin/shares/{$s['id']}", null, null, ['X-LockSend' => '1'], $cookies)->status);
        $this->assertSame('destroyed', $h->app->shares()->find($s['id'])['status']);
        $this->assertCount(0, Harness::data($h->call('GET', '/api/admin/shares', null, null, [], $cookies))['shares']);

        $h->call('POST', '/api/admin/logout', null, null, ['X-LockSend' => '1'], $cookies);
        $this->assertSame(401, $h->call('GET', '/api/admin/shares', null, null, [], $cookies)->status);
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        [$h, $secret, $login] = $this->adminHarness();
        $code = TOTP::createFromSecret($secret)->now();
        $this->assertSame(200, $h->call('POST', '/api/admin/login', ['login_secret' => $login, 'totp_code' => $code])->status);
        $this->assertSame(401, $h->call('POST', '/api/admin/login', ['login_secret' => $login, 'totp_code' => $code])->status);
    }

    public function testAdminLoginIsRateLimited(): void
    {
        [$h, , $login] = $this->adminHarness();
        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $h->call('POST', '/api/admin/login', ['login_secret' => $login, 'totp_code' => '000000'])->status;
        }
        $this->assertContains(429, $statuses);
    }

    public function testTotpSecretIsEncryptedAtRest(): void
    {
        [$h, $secret] = $this->adminHarness();
        $row = $h->app->admins()->get();
        $this->assertStringNotContainsString($secret, (string) $row['totp_secret_enc']);
    }
}
