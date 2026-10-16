<?php

declare(strict_types=1);

namespace LockSend\Support;

final class Response
{
    /**
     * @param array<string,string> $headers
     * @param (callable():void)|null $stream  emits the body itself (large blobs)
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public $stream = null,
        /** @var list<array{0:string,1:string,2:int,3:bool}> name, value, maxAge, secure */
        public array $cookies = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public static function empty(int $status = 204): self
    {
        return new self($status, ['Cache-Control' => 'no-store']);
    }

    public function withCookie(string $name, string $value, int $maxAge, bool $secure): self
    {
        $this->cookies[] = [$name, $value, $maxAge, $secure];
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($this->cookies as [$name, $value, $maxAge, $secure]) {
            setcookie($name, $value, [
                'expires' => $maxAge === 0 ? 1 : time() + $maxAge,
                'path' => '/api/admin',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
