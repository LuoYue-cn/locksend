<?php

declare(strict_types=1);

namespace LockSend\RateLimit;

use LockSend\Support\Clock;

/** In-process Kv used by the unit tests. */
final class MemoryKv implements Kv
{
    /** @var array<string,array{0:mixed,1:int}> */
    private array $data = [];

    public function __construct(private readonly Clock $clock = new Clock())
    {
    }

    private function live(string $key): bool
    {
        if (!isset($this->data[$key])) {
            return false;
        }
        if ($this->data[$key][1] !== 0 && $this->data[$key][1] <= $this->clock->now()) {
            unset($this->data[$key]);
            return false;
        }
        return true;
    }

    private function exp(int $ttl): int
    {
        return $ttl > 0 ? $this->clock->now() + $ttl : 0;
    }

    public function ping(): bool
    {
        return true;
    }

    public function get(string $key): ?string
    {
        return $this->live($key) ? (string) $this->data[$key][0] : null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->data[$key] = [$value, $this->exp($ttl)];
    }

    public function setNx(string $key, string $value, int $ttl): bool
    {
        if ($this->live($key)) {
            return false;
        }
        $this->set($key, $value, $ttl);
        return true;
    }

    public function del(string ...$keys): void
    {
        foreach ($keys as $k) {
            unset($this->data[$k]);
        }
    }

    public function incrWindow(string $key, int $window): int
    {
        if (!$this->live($key)) {
            $this->data[$key] = [0, $this->exp($window)];
        }
        $this->data[$key][0] = (int) $this->data[$key][0] + 1;
        return (int) $this->data[$key][0];
    }

    public function expire(string $key, int $ttl): void
    {
        if ($this->live($key)) {
            $this->data[$key][1] = $this->exp($ttl);
        }
    }

    public function setBit(string $key, int $offset, int $ttl): void
    {
        if (!$this->live($key)) {
            $this->data[$key] = [[], $this->exp($ttl)];
        }
        $this->data[$key][0][$offset] = true;
        $this->data[$key][1] = $this->exp($ttl);
    }

    public function bitCount(string $key): int
    {
        return $this->live($key) ? count($this->data[$key][0]) : 0;
    }
}
