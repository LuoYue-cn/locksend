<?php

declare(strict_types=1);

namespace LockSend\Api;

use LockSend\Support\Base64Url;
use LockSend\Support\HttpException;
use LockSend\Support\Migrator;
use LockSend\Support\Request;
use LockSend\Support\Response;

/**
 * First-run setup. Reachable only until installed.lock exists; afterwards every
 * route here answers 404 and the page itself is no longer served.
 */
final class InstallApi
{
    public function __construct(private readonly App $app)
    {
    }

    private function guard(Request $req): void
    {
        if ($this->app->config->isInstalled()) {
            throw new HttpException(404, 'not_found');
        }
        $key = 'install:' . $this->app->limiter()->clientKey($req);
        if (!$this->app->limiter()->allow($key, 30, 900)) {
            throw new HttpException(429, 'rate_limited');
        }
    }

    private function tokenOk(string $token): bool
    {
        $file = $this->app->config->setupTokenFile();
        if (!is_file($file) || preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $token) !== 1) {
            return false;
        }
        $stored = trim((string) file_get_contents($file));
        return hash_equals($stored, hash('sha256', $token));
    }

    private function requireToken(Request $req, array $body): string
    {
        $token = $body['setup_token'] ?? null;
        if (!is_string($token) || !$this->tokenOk($token)) {
            $key = 'install-bad-token';
            if (!$this->app->limiter()->allow($key, 10, 900)) {
                throw new HttpException(429, 'rate_limited');
            }
            throw new HttpException(403, 'bad_setup_token');
        }
        return $token;
    }

    /** @param array<string,string> $p */
    public function status(Request $req, array $p = []): Response
    {
        if ($this->app->config->isInstalled()) {
            return Response::json(['installed' => true]);
        }
        $this->guard($req);
        $c = $this->app->config;
        $dirOk = (function () use ($c): bool {
            try {
                $c->ensureDirs();
                return is_writable($c->dataDir);
            } catch (\Throwable) {
                return false;
            }
        })();
        $redisOk = false;
        try {
            $redisOk = $this->app->kv()->ping();
        } catch (\Throwable) {
        }
        $pepperOk = $this->app->secrets()->source() === 'env' || $dirOk;
        $checks = [
            'sodium' => extension_loaded('sodium'),
            'pdo' => extension_loaded('pdo')
                && in_array(explode(':', $c->dbDsn)[0], \PDO::getAvailableDrivers(), true),
            'data_dir' => $dirOk,
            'redis' => $redisOk,
            'secret' => $pepperOk,
        ];
        return Response::json([
            'installed' => false,
            'needs_token' => true,
            'token_present' => is_file($c->setupTokenFile()),
            'checks' => $checks,
            'ready' => !in_array(false, $checks, true),
        ]);
    }

    /** Validates the setup token and hands out the TOTP secret to enrol. @param array<string,string> $p */
    public function begin(Request $req, array $p = []): Response
    {
        $this->guard($req);
        $body = $req->json();
        $token = $this->requireToken($req, $body);
        $this->app->secrets()->pepper(true);
        $totp = $this->app->totp()->generate('admin');
        $this->app->kv()->set('install:totp:' . hash('sha256', $token), $totp['stored'], 1800);
        return Response::json(['totp_secret' => $totp['secret'], 'totp_uri' => $totp['uri']]);
    }

    /** @param array<string,string> $p */
    public function finish(Request $req, array $p = []): Response
    {
        $this->guard($req);
        $body = $req->json();
        $token = $this->requireToken($req, $body);

        $pub = Base64Url::decodeExact($body['admin_pub'] ?? null, 65);
        $wrapSalt = Base64Url::decodeExact($body['wrap_salt'] ?? null, 16);
        $login = Base64Url::decodeExact($body['login_secret'] ?? null, 32);
        $wrapped = is_string($body['wrapped_priv'] ?? null) ? Base64Url::decode($body['wrapped_priv']) : null;
        $code = $body['totp_code'] ?? null;
        if (
            $pub === null || $pub[0] !== "\x04" || $wrapSalt === null || $login === null
            || $wrapped === null || strlen($wrapped) < 28 || strlen($wrapped) > 1024 || !is_string($code)
        ) {
            throw new HttpException(400, 'bad_request');
        }
        $pending = $this->app->kv()->get('install:totp:' . hash('sha256', $token));
        if ($pending === null) {
            throw new HttpException(409, 'begin_required');
        }
        if (!$this->app->totp()->verify($pending, $code)) {
            throw new HttpException(401, 'bad_totp');
        }

        $config = $this->app->config;
        $config->ensureDirs();
        $guard = fopen($config->dataDir . '/install.guard', 'cb');
        if ($guard === false || !flock($guard, LOCK_EX | LOCK_NB)) {
            throw new HttpException(409, 'install_in_progress');
        }
        try {
            if ($config->isInstalled()) {
                throw new HttpException(404, 'not_found');
            }
            $this->app->secrets()->pepper(true);
            (new Migrator($this->app->db(), dirname(__DIR__, 2) . '/migrations'))->run();
            $this->app->admins()->save(
                Base64Url::encode($pub),
                Base64Url::encode($wrapped),
                Base64Url::encode($wrapSalt),
                $this->app->shareAuth()->verifier($login),
                $pending
            );
            $this->app->db()->setSetting('installed_at', (string) $this->app->clock->now());
            file_put_contents($config->installLockFile(), (string) $this->app->clock->now());
            chmod($config->installLockFile(), 0640);
            @unlink($config->setupTokenFile());
            $this->app->kv()->del('install:totp:' . hash('sha256', $token));
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
        return Response::json(['installed' => true], 201);
    }
}
