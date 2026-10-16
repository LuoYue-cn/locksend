<?php

declare(strict_types=1);

namespace LockSend\RateLimit;

/** Minimal key/value surface the app needs from Redis. Memory-only data. */
interface Kv
{
    public function ping(): bool;

    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttl): void;

    /** SET NX EX; true when the key was created. */
    public function setNx(string $key, string $value, int $ttl): bool;

    public function del(string ...$keys): void;

    /** Atomically-enough fixed-window counter: creates with TTL, then increments. */
    public function incrWindow(string $key, int $window): int;

    public function expire(string $key, int $ttl): void;

    public function setBit(string $key, int $offset, int $ttl): void;

    public function bitCount(string $key): int;
}
