<?php

declare(strict_types=1);

namespace LockSend\Api;

use LockSend\Storage\ShareService;
use LockSend\Support\Base64Url;
use LockSend\Support\Config;
use LockSend\Support\HttpException;
use LockSend\Support\Request;
use LockSend\Support\Response;

final class UploadApi
{
    public function __construct(private readonly App $app)
    {
    }

    private function requireInstalled(): void
    {
        if (!$this->app->config->isInstalled() || $this->app->admins()->publicKey() === null) {
            throw new HttpException(503, 'not_installed');
        }
    }

    private function rateLimit(string $bucket, int $max, int $window, Request $req): void
    {
        $key = $bucket . ':' . $this->app->limiter()->clientKey($req);
        if (!$this->app->limiter()->allow($key, $max, $window)) {
            throw new HttpException(429, 'rate_limited', [], ['Retry-After' => (string) $window]);
        }
    }

    /** Verifies the bearer upload token belongs to $id. */
    private function authorize(Request $req, string $id): void
    {
        $subject = $this->app->uploadSessions()->resolve($req->bearer());
        if ($subject === null || !hash_equals($subject, $id)) {
            throw new HttpException(401, 'unauthorized');
        }
    }

    /** @param array<string,string> $p */
    public function reserve(Request $req, array $p = []): Response
    {
        $this->requireInstalled();
        $this->rateLimit('reserve', $this->app->settings()->createPerHour(), 3600, $req);
        if ($this->app->blobs()->freeBytes() < $this->app->settings()->minFreeBytes()) {
            throw new HttpException(507, 'storage_full');
        }
        $id = Base64Url::encode(random_bytes(16));
        $token = $this->app->uploadSessions()->create($id);
        return Response::json(['share_id' => $id, 'upload_token' => $token], 201);
    }

    /** @param array<string,string> $p */
    public function create(Request $req, array $p): Response
    {
        $this->requireInstalled();
        $id = $p['id'];
        $this->authorize($req, $id);
        $b = $req->json();
        $settings = $this->app->settings();

        $salt = Base64Url::decodeExact($b['salt'] ?? null, 16);
        $auth = Base64Url::decodeExact($b['auth'] ?? null, 32);
        $encMeta = is_string($b['enc_meta'] ?? null) ? Base64Url::decode($b['enc_meta']) : null;
        $sealed = is_string($b['sealed_name'] ?? null) ? Base64Url::decode($b['sealed_name']) : null;
        $chunks = $b['chunk_count'] ?? null;
        $maxFails = $b['max_fails'] ?? null;
        $failAction = $b['fail_action'] ?? null;
        $ttlDays = $b['ttl_days'] ?? null;
        $burn = $b['burn_after_complete'] ?? null;
        $maxCompletions = $b['max_completions'] ?? null;

        $valid = ($b['kdf_v'] ?? null) === Config::KDF['v']
            && $salt !== null && $auth !== null
            && $encMeta !== null && strlen($encMeta) >= 28 && strlen($encMeta) <= 2048
            && $sealed !== null && strlen($sealed) >= 93 && strlen($sealed) <= 2048 && $sealed[0] === "\x04"
            && is_int($chunks) && $chunks >= 1
            && is_int($maxFails) && in_array($maxFails, Config::MAX_FAILS_OPTIONS, true)
            && is_string($failAction) && in_array($failAction, Config::FAIL_ACTIONS, true)
            && is_int($ttlDays) && $ttlDays >= 1 && $ttlDays <= $settings->ttlMaxDays()
            && is_bool($burn)
            && is_int($maxCompletions) && $maxCompletions >= 1 && $maxCompletions <= 100;
        if (!$valid) {
            throw new HttpException(400, 'bad_request');
        }
        if ($chunks > $settings->maxChunkCount()) {
            throw new HttpException(413, 'file_too_large', ['max_file_bytes' => $settings->maxFileBytes()]);
        }

        $now = $this->app->clock->now();
        try {
            $this->app->db()->exec(
                'INSERT INTO shares (id, status, created_at, expires_at, ttl_seconds, kdf_v, salt, auth_verifier,'
                . ' enc_meta, sealed_name, chunk_count, max_fails, fail_action, burn_after_complete,'
                . ' max_completions, upload_token_hash)'
                . " VALUES (?, 'uploading', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')",
                [
                    $id, $now, $now + Config::UPLOAD_WINDOW, $ttlDays * 86400, Config::KDF['v'],
                    Base64Url::encode($salt), $this->app->shareAuth()->verifier($auth),
                    Base64Url::encode($encMeta), Base64Url::encode($sealed), $chunks,
                    $maxFails, $failAction, $burn ? 1 : 0, $maxCompletions,
                ]
            );
        } catch (\PDOException) {
            throw new HttpException(409, 'exists');
        }
        return Response::json(['share_id' => $id], 201);
    }

    /** @return array<string,mixed> */
    private function uploadingShare(string $id): array
    {
        $row = $this->app->shares()->find($id);
        if ($row === null || $row['status'] !== ShareService::UPLOADING) {
            throw new HttpException(409, 'not_uploading');
        }
        return $row;
    }

    /** @param array<string,string> $p */
    public function putChunk(Request $req, array $p): Response
    {
        $id = $p['id'];
        $n = (int) $p['n'];
        $this->authorize($req, $id);
        $share = $this->uploadingShare($id);
        $count = (int) $share['chunk_count'];
        if ($n >= $count) {
            throw new HttpException(400, 'bad_index');
        }
        $len = $req->contentLength();
        if ($len === null) {
            throw new HttpException(411, 'length_required');
        }
        $blobs = $this->app->blobs();
        if ($blobs->freeBytes() < $this->app->settings()->minFreeBytes() + $len) {
            throw new HttpException(507, 'storage_full');
        }
        [$tmp, $size] = $blobs->receive($req->bodyStream(), Config::CCHUNK);
        $okSize = $n < $count - 1 ? $size === Config::CCHUNK : ($size >= Config::TAG && $size <= Config::CCHUNK);
        if (!$okSize || $size !== $len) {
            $blobs->discard($tmp);
            throw new HttpException(400, 'bad_chunk_size');
        }
        $blobs->commit($tmp, $id, $n);
        return Response::empty();
    }

    /** @param array<string,string> $p */
    public function status(Request $req, array $p): Response
    {
        $id = $p['id'];
        $this->authorize($req, $id);
        $share = $this->uploadingShare($id);
        $have = array_keys($this->app->blobs()->sizes($id));
        $ranges = [];
        foreach ($have as $i) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][1] === $i - 1) {
                $ranges[$last][1] = $i;
            } else {
                $ranges[] = [$i, $i];
            }
        }
        return Response::json(['chunk_count' => (int) $share['chunk_count'], 'received' => $ranges]);
    }

    /** @param array<string,string> $p */
    public function finalize(Request $req, array $p): Response
    {
        $id = $p['id'];
        $this->authorize($req, $id);
        $share = $this->uploadingShare($id);
        $count = (int) $share['chunk_count'];
        $sizes = $this->app->blobs()->sizes($id);
        for ($i = 0; $i < $count; $i++) {
            $s = $sizes[$i] ?? null;
            $ok = $s !== null
                && ($i < $count - 1 ? $s === Config::CCHUNK : ($s >= Config::TAG && $s <= Config::CCHUNK));
            if (!$ok) {
                throw new HttpException(409, 'incomplete', ['missing' => $i]);
            }
        }
        if (count($sizes) !== $count) {
            throw new HttpException(409, 'unexpected_chunks');
        }
        $expires = $this->app->clock->now() + (int) $share['ttl_seconds'];
        $changed = $this->app->db()->exec(
            "UPDATE shares SET status = 'ready', expires_at = ? WHERE id = ? AND status = 'uploading'",
            [$expires, $id]
        );
        if ($changed !== 1) {
            throw new HttpException(409, 'not_uploading');
        }
        $this->app->uploadSessions()->destroy((string) $req->bearer());
        return Response::json(['share_id' => $id, 'expires_at' => $expires]);
    }

    /** @param array<string,string> $p */
    public function abort(Request $req, array $p): Response
    {
        $id = $p['id'];
        $this->authorize($req, $id);
        $row = $this->app->shares()->find($id);
        if ($row !== null && $row['status'] === ShareService::UPLOADING) {
            $this->app->shares()->close($id, ShareService::DESTROYED);
        }
        $this->app->uploadSessions()->destroy((string) $req->bearer());
        return Response::empty();
    }
}
