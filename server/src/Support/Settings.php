<?php

declare(strict_types=1);

namespace LockSend\Support;

/** Operator-tunable limits. Environment wins over the settings table, which wins over defaults. */
final class Settings
{
    /** @var array<string,string>|null */
    private ?array $rows = null;

    public function __construct(private readonly Config $config, private readonly ?Db $db)
    {
    }

    private function row(string $key): ?string
    {
        if ($this->rows === null) {
            $this->rows = [];
            if ($this->db !== null && $this->config->isInstalled()) {
                try {
                    foreach ($this->db->all('SELECT k, v FROM settings') as $r) {
                        $this->rows[(string) $r['k']] = (string) $r['v'];
                    }
                } catch (\Throwable) {
                }
            }
        }
        return $this->rows[$key] ?? null;
    }

    private function int(string $env, string $key, int $default, int $min, int $max): int
    {
        $v = $this->config->get($env) ?? $this->row($key);
        if ($v === null || !preg_match('/^\d{1,12}$/D', $v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }

    public function ttlMaxDays(): int
    {
        return $this->int('LOCKSEND_TTL_MAX_DAYS', 'ttl_max_days', 30, 1, 365);
    }

    public function ttlDefaultDays(): int
    {
        return min($this->ttlMaxDays(), $this->int('LOCKSEND_TTL_DEFAULT_DAYS', 'ttl_default_days', 7, 1, 365));
    }

    /** Uploads are refused when the data volume has less free space than this. */
    public function minFreeBytes(): int
    {
        return $this->int('LOCKSEND_MIN_FREE_BYTES', 'min_free_bytes', 536870912, 0, PHP_INT_MAX);
    }

    /** Largest plaintext file accepted, in bytes (default 500 MB). */
    public function maxFileBytes(): int
    {
        return $this->int('LOCKSEND_MAX_FILE_BYTES', 'max_file_bytes', 500 * 1000 * 1000, 1, PHP_INT_MAX);
    }

    /**
     * The server never sees the plaintext size, only the chunk count, so the size limit is
     * enforced as a chunk-count limit: ceil(max_file_bytes / CHUNK).
     */
    public function maxChunkCount(): int
    {
        return min(Config::MAX_CHUNK_COUNT, max(1, intdiv($this->maxFileBytes() + Config::CHUNK - 1, Config::CHUNK)));
    }

    public function createPerHour(): int
    {
        return $this->int('LOCKSEND_CREATE_PER_HOUR', 'create_per_hour', 20, 1, 100000);
    }
}
