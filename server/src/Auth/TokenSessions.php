<?php

declare(strict_types=1);

namespace LockSend\Auth;

use LockSend\RateLimit\Kv;
use LockSend\Support\Base64Url;

/**
 * Opaque bearer tokens kept only in Redis (memory), keyed by SHA-256 of the token
 * so a Redis dump does not yield usable credentials. Idle timeout is sliding.
 */
final class TokenSessions
{
    public function __construct(
        private readonly Kv $kv,
        private readonly string $prefix,
        private readonly int $idleTtl,
    ) {
    }

    private function key(string $token): string
    {
        return $this->prefix . ':' . hash('sha256', $token);
    }

    public function create(string $subject): string
    {
        $token = Base64Url::encode(random_bytes(32));
        $this->kv->set($this->key($token), $subject, $this->idleTtl);
        return $token;
    }

    /** Returns the subject and slides the idle timeout, or null when unknown/expired. */
    public function resolve(?string $token): ?string
    {
        if ($token === null || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }
        $v = $this->kv->get($this->key($token));
        if ($v !== null) {
            $this->kv->expire($this->key($token), $this->idleTtl);
        }
        return $v;
    }

    public function destroy(string $token): void
    {
        $this->kv->del($this->key($token));
    }
}
