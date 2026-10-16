<?php

declare(strict_types=1);

namespace LockSend\Support;

/**
 * server_pepper: taken from LOCKSEND_PEPPER when set (preferred, never stored in the DB).
 * Otherwise a 0600 file outside the web root, created once at install time.
 */
final class Secrets
{
    private ?string $cached = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function source(): string
    {
        return $this->config->get('LOCKSEND_PEPPER') !== null ? 'env' : 'file';
    }

    public function pepper(bool $create = false): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $env = $this->config->get('LOCKSEND_PEPPER');
        if ($env !== null) {
            return $this->cached = self::parse($env);
        }
        $file = $this->config->pepperFile();
        if (!is_file($file)) {
            if (!$create) {
                throw new \RuntimeException('pepper missing');
            }
            $this->config->ensureDirs();
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, bin2hex(random_bytes(32)));
            chmod($tmp, 0600);
            if (!link($tmp, $file) && !is_file($file)) {
                unlink($tmp);
                throw new \RuntimeException('cannot write pepper');
            }
            unlink($tmp);
        }
        return $this->cached = self::parse((string) file_get_contents($file));
    }

    public static function parse(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, 'base64:')) {
            $raw = base64_decode(substr($value, 7), true);
        } elseif (preg_match('/^[0-9a-f]{64,}$/i', $value) === 1 && strlen($value) % 2 === 0) {
            $raw = hex2bin($value);
        } else {
            $raw = $value;
        }
        if (!is_string($raw) || strlen($raw) < 32) {
            throw new \RuntimeException('pepper must be at least 32 bytes');
        }
        return $raw;
    }

    /** Sub-key for encrypting the TOTP secret at rest. */
    public function totpKey(): string
    {
        return hash_hkdf('sha256', $this->pepper(), 32, 'totp-wrap-v1');
    }
}
