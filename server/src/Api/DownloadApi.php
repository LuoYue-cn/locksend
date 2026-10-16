<?php

declare(strict_types=1);

namespace LockSend\Api;

use LockSend\Storage\ShareService;
use LockSend\Support\Base64Url;
use LockSend\Support\Config;
use LockSend\Support\HttpException;
use LockSend\Support\Request;
use LockSend\Support\Response;

final class DownloadApi
{
    public function __construct(private readonly App $app)
    {
    }

    private function rateLimit(string $bucket, int $max, int $window, Request $req): void
    {
        $key = $bucket . ':' . $this->app->limiter()->clientKey($req);
        if (!$this->app->limiter()->allow($key, $max, $window)) {
            throw new HttpException(429, 'rate_limited', [], ['Retry-After' => (string) $window]);
        }
    }

    private function progressKey(string $token): string
    {
        return 'dlp:' . substr(hash('sha256', $token), 0, 32);
    }

    /**
     * Returns the public KDF salt. Unknown, closed and expired shares are all
     * reported the same way (410 gone), so nothing about a share is revealed
     * before the code has been verified.
     *
     * @param array<string,string> $p
     */
    public function challenge(Request $req, array $p): Response
    {
        if (!$this->app->config->isInstalled()) {
            throw new HttpException(503, 'not_installed');
        }
        $this->rateLimit('challenge', 120, 600, $req);
        $row = $this->app->shares()->find($p['id']);
        if ($row === null || !ShareService::isDownloadable($row, $this->app->clock->now())) {
            throw new HttpException(410, 'gone');
        }
        return Response::json(['salt' => $row['salt'], 'kdf' => Config::KDF]);
    }

    /** @param array<string,string> $p */
    public function unlock(Request $req, array $p): Response
    {
        if (!$this->app->config->isInstalled()) {
            throw new HttpException(503, 'not_installed');
        }
        $this->rateLimit('unlock', 60, 600, $req);
        $auth = Base64Url::decodeExact($req->json()['auth'] ?? null, 32);
        if ($auth === null) {
            throw new HttpException(400, 'bad_request');
        }
        $row = $this->app->shareAuth()->attempt($p['id'], $auth);
        $token = $this->app->downloadSessions()->create($p['id']);
        return Response::json([
            'download_token' => $token,
            'enc_meta' => $row['enc_meta'],
            'chunk_count' => (int) $row['chunk_count'],
            'expires_at' => (int) $row['expires_at'],
        ]);
    }

    /** @return array<string,mixed> */
    private function activeShare(Request $req, string $id): array
    {
        $token = $req->bearer();
        $subject = $this->app->downloadSessions()->resolve($token);
        if ($subject === null || !hash_equals($subject, $id)) {
            throw new HttpException(401, 'unauthorized');
        }
        $row = $this->app->shares()->find($id);
        if ($row === null || !ShareService::isDownloadable($row, $this->app->clock->now())) {
            throw new HttpException(410, 'gone');
        }
        return $row;
    }

    /** @param array<string,string> $p */
    public function chunk(Request $req, array $p): Response
    {
        $id = $p['id'];
        $n = (int) $p['n'];
        $row = $this->activeShare($req, $id);
        if ($n >= (int) $row['chunk_count']) {
            throw new HttpException(404, 'not_found');
        }
        $blobs = $this->app->blobs();
        $size = $blobs->size($id, $n);
        if ($size === null) {
            throw new HttpException(410, 'gone');
        }
        $this->app->kv()->setBit($this->progressKey((string) $req->bearer()), $n, 7200);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'no-store',
        ];
        $prefix = $this->app->config->accelPrefix;
        if ($prefix !== '') {
            $headers['X-Accel-Redirect'] = $prefix . '/' . $id . '/' . $n;
            unset($headers['Content-Length']);
            return new Response(200, $headers);
        }
        $path = $blobs->path($id, $n);
        return new Response(200, $headers, '', static function () use ($path): void {
            $h = fopen($path, 'rb');
            if ($h === false) {
                return;
            }
            while (!feof($h)) {
                echo fread($h, 262144);
                flush();
            }
            fclose($h);
        });
    }

    /** @param array<string,string> $p */
    public function complete(Request $req, array $p): Response
    {
        $id = $p['id'];
        $row = $this->activeShare($req, $id);
        $token = (string) $req->bearer();
        $served = $this->app->kv()->bitCount($this->progressKey($token));
        if ($served < (int) $row['chunk_count']) {
            throw new HttpException(409, 'incomplete');
        }
        $burned = $this->app->db()->tx(function () use ($id): bool {
            $r = $this->app->db()->one('SELECT * FROM shares WHERE id = ?' . $this->app->db()->forUpdate(), [$id]);
            if ($r === null || !ShareService::isOpen($r)) {
                throw new HttpException(410, 'gone');
            }
            $done = (int) $r['completions'] + 1;
            if ((int) $r['burn_after_complete'] === 1 && $done >= (int) $r['max_completions']) {
                $this->app->shares()->markClosed($id, ShareService::BURNED);
                return true;
            }
            $this->app->db()->exec("UPDATE shares SET completions = ?, status = 'ready' WHERE id = ?", [$done, $id]);
            return false;
        });
        if ($burned) {
            $this->app->shares()->purge($id);
        }
        $this->app->kv()->del($this->progressKey($token));
        $this->app->downloadSessions()->destroy($token);
        return Response::json(['burned' => $burned]);
    }
}
