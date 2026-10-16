<?php

declare(strict_types=1);

namespace LockSend\Support;

/**
 * Static protocol constants plus environment-derived deployment settings.
 * KDF parameters are fixed here and served by /api/config; clients cannot choose them.
 */
final class Config
{
    public const CHUNK = 4194304;
    public const TAG = 16;
    public const CCHUNK = self::CHUNK + self::TAG;
    public const KDF = ['v' => 1, 'm' => 65536, 't' => 3, 'p' => 1, 'len' => 32];
    public const MAX_FAILS_OPTIONS = [3, 5, 10];
    public const FAIL_ACTIONS = ['destroy', 'freeze_24h'];
    public const UPLOAD_WINDOW = 86400;
    public const FREEZE_SECONDS = 86400;
    public const TOMBSTONE_SECONDS = 604800;
    public const MAX_CHUNK_COUNT = 16777216;
    public const SESSION_IDLE = 21600;
    public const RESERVE_TTL = 7200;

    public readonly string $dataDir;
    public readonly string $redisUrl;
    public readonly string $dbDsn;
    public readonly ?string $dbUser;
    public readonly ?string $dbPass;
    public readonly string $accelPrefix;
    public readonly string $templatesDir;
    public readonly bool $trustRealIp;

    /** @param array<string,string> $env explicit overrides (tests); falls back to process environment */
    public function __construct(private readonly array $env = [])
    {
        $this->dataDir = rtrim($this->get('LOCKSEND_DATA_DIR') ?? dirname(__DIR__, 2) . '/data', '/');
        $this->redisUrl = $this->get('LOCKSEND_REDIS') ?? 'tcp://127.0.0.1:6379';
        $this->dbDsn = $this->get('LOCKSEND_DB_DSN') ?? 'sqlite:' . $this->dataDir . '/locksend.sqlite';
        $this->dbUser = $this->get('LOCKSEND_DB_USER');
        $this->dbPass = $this->get('LOCKSEND_DB_PASS');
        $this->accelPrefix = rtrim($this->get('LOCKSEND_ACCEL_PREFIX') ?? '', '/');
        $this->templatesDir = $this->get('LOCKSEND_TEMPLATES_DIR') ?? dirname(__DIR__, 2) . '/templates';
        $this->trustRealIp = ($this->get('LOCKSEND_TRUST_REAL_IP') ?? '0') === '1';
    }

    public function get(string $name): ?string
    {
        if (array_key_exists($name, $this->env)) {
            return $this->env[$name];
        }
        $v = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        return (is_string($v) && $v !== '') ? $v : null;
    }

    public function blobsDir(): string
    {
        return $this->dataDir . '/blobs';
    }

    public function tmpDir(): string
    {
        return $this->dataDir . '/tmp';
    }

    public function installLockFile(): string
    {
        return $this->dataDir . '/installed.lock';
    }

    public function setupTokenFile(): string
    {
        return $this->dataDir . '/setup-token.sha256';
    }

    public function pepperFile(): string
    {
        return $this->dataDir . '/secrets/pepper';
    }

    public function isInstalled(): bool
    {
        return is_file($this->installLockFile());
    }

    /** Base directory for runtime files; creates the private sub-directories on demand. */
    public function ensureDirs(): void
    {
        foreach ([$this->dataDir, $this->blobsDir(), $this->tmpDir(), $this->dataDir . '/secrets'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('cannot create data directory');
            }
        }
    }
}
