<?php

declare(strict_types=1);

namespace LockSend\Support;

final class Request
{
    public const MAX_JSON = 65536;

    /** @var array<string,string> lower-cased header name => value */
    private array $headers;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @param array<string,string> $cookies
     * @param resource|null $bodyStream
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $headers = [],
        public readonly array $query = [],
        public readonly array $cookies = [],
        private $bodyStream = null,
        private readonly string $remoteAddr = '0.0.0.0',
        public readonly bool $https = false,
        private readonly bool $trustRealIp = false,
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function fromGlobals(Config $config): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($v)) {
                $query[(string) $k] = $v;
            }
        }
        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (is_string($v)) {
                $cookies[(string) $k] = $v;
            }
        }
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || (strtolower($headers['x-forwarded-proto'] ?? '') === 'https');
        return new self(
            strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            is_string($path) ? rawurldecode($path) : '/',
            $headers,
            $query,
            $cookies,
            null,
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0',
            $https,
            $config->trustRealIp,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearer(): ?string
    {
        $h = $this->header('authorization');
        if ($h !== null && preg_match('/^Bearer ([A-Za-z0-9_-]{20,128})$/D', $h, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** Never logged or persisted; only fed to an HMAC for short-lived rate-limit buckets. */
    public function clientAddress(): string
    {
        if ($this->trustRealIp) {
            $real = $this->header('x-real-ip');
            if ($real !== null && filter_var($real, FILTER_VALIDATE_IP) !== false) {
                return $real;
            }
        }
        return $this->remoteAddr;
    }

    public function contentLength(): ?int
    {
        $h = $this->header('content-length');
        return ($h !== null && ctype_digit($h)) ? (int) $h : null;
    }

    /** @return resource */
    public function bodyStream()
    {
        if ($this->bodyStream === null) {
            $s = fopen('php://input', 'rb');
            if ($s === false) {
                throw new \RuntimeException('cannot open request body');
            }
            $this->bodyStream = $s;
        }
        return $this->bodyStream;
    }

    /** Reads at most $max bytes; returns null when the body is larger. */
    public function readBody(int $max): ?string
    {
        $data = stream_get_contents($this->bodyStream(), $max + 1);
        if ($data === false || strlen($data) > $max) {
            return null;
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $ct = strtolower($this->header('content-type') ?? '');
        if (!str_starts_with($ct, 'application/json')) {
            throw new HttpException(415, 'unsupported_media_type');
        }
        $raw = $this->readBody(self::MAX_JSON);
        if ($raw === null) {
            throw new HttpException(413, 'too_large');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'bad_json');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new HttpException(400, 'bad_json');
        }
        return $data;
    }
}
