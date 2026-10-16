<?php

declare(strict_types=1);

namespace LockSend\RateLimit;

/**
 * Redis-backed Kv. Uses ext-redis rawCommand() when the extension is loaded and
 * otherwise a tiny RESP client over a stream socket (no third-party code).
 */
final class RedisKv implements Kv
{
    private ?\Redis $ext = null;
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private readonly string $url,
        private readonly float $timeout = 2.0,
        private readonly bool $useExtension = true
    ) {
    }

    /**
     * Accepts tcp://host[:port], unix:///absolute/path.sock and unix:/absolute/path.sock.
     * parse_url() rejects the three-slash unix form, so it is handled explicitly.
     *
     * @return array{scheme:string,host?:string,port?:int,path?:string,pass?:string}
     */
    public static function parseUrl(string $url): array
    {
        if (preg_match('#^unix:(?://)?(/[^?\#]+)$#D', $url, $m) === 1) {
            return ['scheme' => 'unix', 'path' => $m[1]];
        }
        $u = parse_url($url);
        if ($u === false || ($u['scheme'] ?? 'tcp') !== 'tcp') {
            throw new \RuntimeException('bad redis url');
        }
        $out = ['scheme' => 'tcp', 'host' => $u['host'] ?? '127.0.0.1', 'port' => $u['port'] ?? 6379];
        if (isset($u['pass'])) {
            $out['pass'] = urldecode($u['pass']);
        }
        return $out;
    }

    /** @param list<string|int> $args */
    private function cmd(array $args): mixed
    {
        $this->connect();
        if ($this->ext !== null) {
            return $this->ext->rawCommand(...array_map('strval', $args));
        }
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string) $a;
            $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        $this->writeAll($out);
        return $this->readReply();
    }

    private function connect(): void
    {
        if ($this->ext !== null || $this->sock !== null) {
            return;
        }
        $u = self::parseUrl($this->url);
        $scheme = $u['scheme'];
        $pass = $u['pass'] ?? null;
        if ($this->useExtension && class_exists(\Redis::class)) {
            $r = new \Redis();
            $ok = $scheme === 'unix'
                ? $r->connect($u['path'] ?? '', 0, $this->timeout)
                : $r->connect($u['host'] ?? '127.0.0.1', $u['port'] ?? 6379, $this->timeout);
            if (!$ok) {
                throw new \RuntimeException('redis unavailable');
            }
            if ($pass !== null) {
                $r->auth($pass);
            }
            $this->ext = $r;
            return;
        }
        $target = $scheme === 'unix'
            ? 'unix://' . ($u['path'] ?? '')
            : 'tcp://' . ($u['host'] ?? '127.0.0.1') . ':' . ($u['port'] ?? 6379);
        $s = @stream_socket_client($target, $errno, $errstr, $this->timeout);
        if ($s === false) {
            throw new \RuntimeException('redis unavailable');
        }
        stream_set_timeout($s, (int) ceil($this->timeout));
        $this->sock = $s;
        if ($pass !== null) {
            $this->cmd(['AUTH', $pass]);
        }
    }

    private function writeAll(string $data): void
    {
        $len = strlen($data);
        for ($off = 0; $off < $len;) {
            $n = @fwrite($this->sock, substr($data, $off));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('redis write failed');
            }
            $off += $n;
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->sock);
        if ($line === false) {
            throw new \RuntimeException('redis read failed');
        }
        return rtrim($line, "\r\n");
    }

    private function readReply(): mixed
    {
        $line = $this->readLine();
        $type = $line[0] ?? '';
        $rest = substr($line, 1);
        switch ($type) {
            case '+':
                return $rest;
            case '-':
                throw new \RuntimeException('redis error: ' . $rest);
            case ':':
                return (int) $rest;
            case '$':
                $n = (int) $rest;
                if ($n < 0) {
                    return null;
                }
                $buf = '';
                while (strlen($buf) < $n + 2) {
                    $chunk = fread($this->sock, $n + 2 - strlen($buf));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('redis read failed');
                    }
                    $buf .= $chunk;
                }
                return substr($buf, 0, $n);
            case '*':
                $n = (int) $rest;
                if ($n < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $n; $i++) {
                    $items[] = $this->readReply();
                }
                return $items;
            default:
                throw new \RuntimeException('redis protocol error');
        }
    }

    public function ping(): bool
    {
        try {
            $r = $this->cmd(['PING']);
            return $r === 'PONG' || $r === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function get(string $key): ?string
    {
        $v = $this->cmd(['GET', $key]);
        return ($v === null || $v === false) ? null : (string) $v;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->cmd($ttl > 0 ? ['SET', $key, $value, 'EX', $ttl] : ['SET', $key, $value]);
    }

    public function setNx(string $key, string $value, int $ttl): bool
    {
        $r = $this->cmd(['SET', $key, $value, 'EX', $ttl, 'NX']);
        return $r === 'OK' || $r === true;
    }

    public function del(string ...$keys): void
    {
        if ($keys !== []) {
            $this->cmd(['DEL', ...$keys]);
        }
    }

    public function incrWindow(string $key, int $window): int
    {
        $this->cmd(['SET', $key, '0', 'EX', $window, 'NX']);
        return (int) $this->cmd(['INCR', $key]);
    }

    public function expire(string $key, int $ttl): void
    {
        $this->cmd(['EXPIRE', $key, $ttl]);
    }

    public function setBit(string $key, int $offset, int $ttl): void
    {
        $this->cmd(['SETBIT', $key, $offset, 1]);
        $this->cmd(['EXPIRE', $key, $ttl]);
    }

    public function bitCount(string $key): int
    {
        return (int) $this->cmd(['BITCOUNT', $key]);
    }
}
