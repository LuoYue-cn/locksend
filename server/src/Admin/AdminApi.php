<?php

declare(strict_types=1);

namespace LockSend\Admin;

use LockSend\Api\App;
use LockSend\Storage\ShareService;
use LockSend\Support\Base64Url;
use LockSend\Support\HttpException;
use LockSend\Support\Request;
use LockSend\Support\Response;

/**
 * Admin surface: wrapped key material, the list of open shares (id + sealed name + dates)
 * and deletion. No sizes, IPs, status history or download counters are exposed.
 */
final class AdminApi
{
    private const COOKIE = 'ls_admin';

    public function __construct(private readonly App $app)
    {
    }

    private function admin(): array
    {
        if (!$this->app->config->isInstalled()) {
            throw new HttpException(503, 'not_installed');
        }
        $a = $this->app->admins()->get();
        if ($a === null) {
            throw new HttpException(503, 'not_installed');
        }
        return $a;
    }

    private function requireSession(Request $req, bool $mutating): string
    {
        $this->admin();
        $token = $req->cookies[self::COOKIE] ?? null;
        if ($this->app->adminSessions()->resolve($token) === null) {
            throw new HttpException(401, 'unauthorized');
        }
        if ($mutating && $req->header('x-locksend') !== '1') {
            throw new HttpException(403, 'csrf');
        }
        return (string) $token;
    }

    /** @param array<string,string> $p */
    public function salt(Request $req, array $p = []): Response
    {
        $a = $this->admin();
        return Response::json(['wrap_salt' => $a['wrap_salt'], 'kdf' => \LockSend\Support\Config::KDF]);
    }

    /** @param array<string,string> $p */
    public function login(Request $req, array $p = []): Response
    {
        $a = $this->admin();
        $limiter = $this->app->limiter();
        $client = 'adminlogin:' . $limiter->clientKey($req);
        if (!$limiter->allow($client, 8, 900) || !$limiter->allow('adminlogin:global', 40, 900)) {
            throw new HttpException(429, 'rate_limited', [], ['Retry-After' => '900']);
        }
        $b = $req->json();
        $secret = Base64Url::decodeExact($b['login_secret'] ?? null, 32);
        $code = $b['totp_code'] ?? null;
        if ($secret === null || !is_string($code)) {
            throw new HttpException(400, 'bad_request');
        }
        $passOk = hash_equals((string) $a['login_verifier'], $this->app->shareAuth()->verifier($secret));
        // Always evaluate the second factor so timing does not reveal which factor failed.
        $totpOk = $this->app->totp()->check((string) $a['totp_secret_enc'], $code);
        // The code is spent only when both factors were right, so a typo in the
        // password does not lock the admin out of the current 30-second window.
        if (!($passOk && $totpOk) || !$this->app->totp()->consume((string) $a['totp_secret_enc'], $code)) {
            throw new HttpException(401, 'invalid_credentials');
        }
        $token = $this->app->adminSessions()->create('admin');
        $res = Response::json(['wrapped_priv' => $a['wrapped_priv'], 'admin_pub' => $a['pub']]);
        return $res->withCookie(self::COOKIE, $token, 0 + 21600, $req->https);
    }

    /** @param array<string,string> $p */
    public function logout(Request $req, array $p = []): Response
    {
        $token = $this->requireSession($req, true);
        $this->app->adminSessions()->destroy($token);
        return Response::empty()->withCookie(self::COOKIE, '', 0, $req->https);
    }

    /** @param array<string,string> $p */
    public function session(Request $req, array $p = []): Response
    {
        $a = $this->admin();
        $token = $req->cookies[self::COOKIE] ?? null;
        if ($this->app->adminSessions()->resolve($token) === null) {
            throw new HttpException(401, 'unauthorized');
        }
        return Response::json(['wrapped_priv' => $a['wrapped_priv'], 'admin_pub' => $a['pub']]);
    }

    /** @param array<string,string> $p */
    public function list(Request $req, array $p = []): Response
    {
        $this->requireSession($req, false);
        $now = $this->app->clock->now();
        $rows = $this->app->db()->all(
            "SELECT id, sealed_name, created_at, expires_at FROM shares WHERE status IN ('ready','downloading')"
            . ' AND expires_at > ? ORDER BY created_at DESC LIMIT 500',
            [$now]
        );
        return Response::json(['shares' => array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'sealed_name' => $r['sealed_name'],
            'created_at' => (int) $r['created_at'],
            'expires_at' => (int) $r['expires_at'],
        ], $rows)]);
    }

    /** @param array<string,string> $p */
    public function delete(Request $req, array $p): Response
    {
        $this->requireSession($req, true);
        $this->app->shares()->close($p['id'], ShareService::DESTROYED);
        return Response::empty();
    }
}
