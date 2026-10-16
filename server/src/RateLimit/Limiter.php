<?php

declare(strict_types=1);

namespace LockSend\RateLimit;

use LockSend\Support\Request;

final class Limiter
{
    public function __construct(private readonly Kv $kv)
    {
    }

    /** Fixed window; returns true while the caller is within `$max` hits per `$window` seconds. */
    public function allow(string $bucket, int $max, int $window): bool
    {
        return $this->kv->incrWindow('rl:' . $bucket, $window) <= $max;
    }

    /**
     * Pseudonymous client bucket for short-lived in-memory counters. The address is
     * HMAC'd with a random key that lives only in Redis for ~2 days, so buckets cannot
     * be mapped back to an address afterwards. Nothing is written to disk or logs.
     */
    public function clientKey(Request $req): string
    {
        $day = intdiv(time(), 86400);
        $keyName = 'rlkey:' . $day;
        $key = $this->kv->get($keyName);
        if ($key === null) {
            $this->kv->setNx($keyName, bin2hex(random_bytes(32)), 172800);
            $key = $this->kv->get($keyName) ?? bin2hex(random_bytes(32));
        }
        return substr(hash_hmac('sha256', $req->clientAddress(), $key), 0, 16);
    }
}
