<?php

declare(strict_types=1);

namespace LockSend\Tests;

use LockSend\Api\App;
use LockSend\RateLimit\MemoryKv;
use LockSend\Support\Base64Url;
use LockSend\Support\Clock;
use LockSend\Support\Config;
use LockSend\Support\Migrator;
use LockSend\Support\Request;
use LockSend\Support\Response;

/** An installed LockSend instance in a throwaway directory, with in-memory Redis. */
final class Harness
{
    public readonly string $dir;
    public readonly Config $config;
    public readonly App $app;
    public readonly TestClock $clock;
    public readonly MemoryKv $kv;
    public readonly string $adminPub;

    public function __construct(bool $install = true, array $env = [])
    {
        $this->dir = sys_get_temp_dir() . '/ls-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0750, true);
        $env += self::databaseEnv(basename($this->dir));
        $this->dbEnv = $env;
        $this->config = new Config($env + [
            'LOCKSEND_DATA_DIR' => $this->dir,
            'LOCKSEND_PEPPER' => str_repeat('ab', 32),
            'LOCKSEND_REDIS' => 'tcp://127.0.0.1:1',
            'LOCKSEND_ACCEL_PREFIX' => '',
            'LOCKSEND_TRUST_REAL_IP' => '0',
        ]);
        $this->clock = new TestClock();
        $this->kv = new MemoryKv($this->clock);
        $this->app = new App($this->config, $this->kv, $this->clock);
        $this->adminPub = Base64Url::encode("\x04" . random_bytes(64));
        if ($install) {
            $this->config->ensureDirs();
            (new Migrator($this->app->db(), dirname(__DIR__) . '/migrations'))->run();
            $this->app->admins()->save($this->adminPub, Base64Url::encode(random_bytes(60)), Base64Url::encode(random_bytes(16)), $this->app->shareAuth()->verifier(random_bytes(32)), 'x');
            file_put_contents($this->config->installLockFile(), '1');
        }
    }

    /** @var array<string,string> */
    public array $dbEnv = [];

    /**
     * With LOCKSEND_TEST_DB_DSN (mysql:host=…;port=…) every Harness gets its own empty database
     * (created here, dropped in the destructor), so the whole suite can run against MariaDB.
     *
     * @return array<string,string>
     */
    private static function databaseEnv(string $unique): array
    {
        $dsn = getenv('LOCKSEND_TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            return [];
        }
        $user = (string) getenv('LOCKSEND_TEST_DB_USER');
        $pass = (string) getenv('LOCKSEND_TEST_DB_PASS');
        $name = 'locksend_test_' . preg_replace('/[^a-z0-9]/', '', strtolower($unique));
        $admin = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        return [
            'LOCKSEND_DB_DSN' => $dsn . ';dbname=' . $name . ';charset=utf8mb4',
            'LOCKSEND_DB_USER' => $user,
            'LOCKSEND_DB_PASS' => $pass,
        ];
    }

    public function __destruct()
    {
        if (isset($this->dbEnv['LOCKSEND_DB_DSN'])) {
            $name = substr($this->dbEnv['LOCKSEND_DB_DSN'], strpos($this->dbEnv['LOCKSEND_DB_DSN'], 'dbname=') + 7);
            $name = explode(';', $name)[0];
            $dsn = explode(';dbname=', $this->dbEnv['LOCKSEND_DB_DSN'])[0];
            try {
                (new \PDO($dsn, $this->dbEnv['LOCKSEND_DB_USER'], $this->dbEnv['LOCKSEND_DB_PASS']))
                    ->exec("DROP DATABASE IF EXISTS `$name`");
            } catch (\Throwable) {
            }
        }
        self::rrmdir($this->dir);
    }

    private static function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            is_dir("$d/$e") && !is_link("$d/$e") ? self::rrmdir("$d/$e") : @unlink("$d/$e");
        }
        @rmdir($d);
    }

    /** @param array<string,mixed>|string|null $body */
    public function call(
        string $method,
        string $path,
        array|string|null $body = null,
        ?string $bearer = null,
        array $headers = [],
        array $cookies = [],
        string $ip = '203.0.113.9'
    ): Response {
        $stream = fopen('php://memory', 'w+b');
        $raw = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : (string) $body;
        fwrite($stream, $raw);
        rewind($stream);
        $h = $headers + [];
        if (is_array($body)) {
            $h['Content-Type'] = 'application/json';
        }
        $h['Content-Length'] = (string) strlen($raw);
        if ($bearer !== null) {
            $h['Authorization'] = 'Bearer ' . $bearer;
        }
        return $this->app->handle(new Request($method, $path, $h, [], $cookies, $stream, $ip));
    }

    /** @return array<string,mixed> */
    public static function data(Response $r): array
    {
        return $r->body === '' ? [] : json_decode($r->body, true, 16, JSON_THROW_ON_ERROR);
    }

    /**
     * Runs a full upload with caller-supplied pseudo-ciphertext (the server never inspects it).
     *
     * @return array{id:string,auth:string,token:string}
     */
    public function uploadShare(int $chunks = 1, array $opts = []): array
    {
        $r = $this->call('POST', '/api/shares/reserve');
        $d = self::data($r);
        $id = $d['share_id'];
        $token = $d['upload_token'];
        $auth = random_bytes(32);
        $create = $this->call('POST', "/api/shares/$id", $opts + [
            'kdf_v' => 1,
            'salt' => Base64Url::encode(random_bytes(16)),
            'auth' => Base64Url::encode($auth),
            'enc_meta' => Base64Url::encode(random_bytes(80)),
            'sealed_name' => Base64Url::encode("\x04" . random_bytes(100)),
            'chunk_count' => $chunks,
            'max_fails' => 5,
            'fail_action' => 'destroy',
            'ttl_days' => 7,
            'burn_after_complete' => true,
            'max_completions' => 1,
        ], $token);
        if ($create->status !== 201) {
            throw new \RuntimeException('create failed: ' . $create->body);
        }
        for ($i = 0; $i < $chunks; $i++) {
            $size = $i < $chunks - 1 ? Config::CCHUNK : 40;
            $p = $this->call('PUT', "/api/shares/$id/chunks/$i", random_bytes($size), $token);
            if ($p->status !== 204) {
                throw new \RuntimeException('chunk failed: ' . $p->body);
            }
        }
        $f = $this->call('POST', "/api/shares/$id/finalize", null, $token);
        if ($f->status !== 200) {
            throw new \RuntimeException('finalize failed: ' . $f->body);
        }
        return ['id' => $id, 'auth' => $auth, 'token' => $token];
    }
}
