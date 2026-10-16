<?php

declare(strict_types=1);

namespace LockSend\Support;

final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Strict, canonical decoding; returns null for anything that would not re-encode identically. */
    public static function decode(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $text) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        if ($raw === false || self::encode($raw) !== $text) {
            return null;
        }
        return $raw;
    }

    /** Decodes and requires an exact byte length. */
    public static function decodeExact(mixed $text, int $length): ?string
    {
        if (!is_string($text)) {
            return null;
        }
        $raw = self::decode($text);
        return ($raw !== null && strlen($raw) === $length) ? $raw : null;
    }
}
