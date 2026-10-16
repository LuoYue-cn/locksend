<?php

declare(strict_types=1);

namespace LockSend\Support;

final class HttpException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $extra  merged into the JSON error body
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($errorCode);
    }
}
