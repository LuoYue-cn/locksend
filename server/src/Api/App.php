<?php

declare(strict_types=1);

namespace LockSend\Api;

use LockSend\Admin\AdminApi;
use LockSend\Admin\AdminRepository;
use LockSend\Auth\ShareAuth;
use LockSend\Auth\TokenSessions;
use LockSend\Auth\Totp;
use LockSend\RateLimit\Kv;
use LockSend\RateLimit\Limiter;
use LockSend\RateLimit\RedisKv;
use LockSend\Storage\BlobStore;
use LockSend\Storage\ShareService;
use LockSend\Support\Clock;
use LockSend\Support\Config;
use LockSend\Support\Db;
use LockSend\Support\HttpException;
use LockSend\Support\Request;
use LockSend\Support\Response;
use LockSend\Support\Router;
use LockSend\Support\Secrets;
use LockSend\Support\Settings;

/** Wires services lazily (so the pre-install path never touches the database) and routes requests. */
final class App
{
    private ?Db $db = null;
    private ?Kv $kv = null;
    private ?Router $router = null;
    /** @var array<string,object> */
    private array $memo = [];

    public function __construct(
        public readonly Config $config,
        private readonly ?Kv $kvOverride = null,
        public readonly Clock $clock = new Clock(),
    ) {
    }

    public function db(): Db
    {
        return $this->db ??= new Db($this->config);
    }

    public function kv(): Kv
    {
        return $this->kv ??= $this->kvOverride ?? new RedisKv($this->config->redisUrl);
    }

    /** @template T of object @param class-string<T> $k @param callable():T $f @return T */
    private function once(string $k, callable $f): object
    {
        return $this->memo[$k] ??= $f();
    }

    public function secrets(): Secrets
    {
        return $this->once(Secrets::class, fn () => new Secrets($this->config));
    }

    public function settings(): Settings
    {
        return $this->once(
            Settings::class,
            fn () => new Settings($this->config, $this->config->isInstalled() ? $this->db() : null)
        );
    }

    public function blobs(): BlobStore
    {
        return $this->once(BlobStore::class, fn () => new BlobStore($this->config));
    }

    public function shares(): ShareService
    {
        return $this->once(ShareService::class, fn () => new ShareService($this->db(), $this->blobs(), $this->clock));
    }

    public function shareAuth(): ShareAuth
    {
        return $this->once(
            ShareAuth::class,
            fn () => new ShareAuth($this->db(), $this->shares(), $this->secrets(), $this->clock)
        );
    }

    public function limiter(): Limiter
    {
        return $this->once(Limiter::class, fn () => new Limiter($this->kv()));
    }

    public function totp(): Totp
    {
        return $this->once(Totp::class, fn () => new Totp($this->secrets(), $this->kv()));
    }

    public function admins(): AdminRepository
    {
        return $this->once(AdminRepository::class, fn () => new AdminRepository($this->db(), $this->clock));
    }

    public function uploadSessions(): TokenSessions
    {
        return $this->once('ups', fn () => new TokenSessions($this->kv(), 'up', Config::UPLOAD_WINDOW));
    }

    public function downloadSessions(): TokenSessions
    {
        return $this->once('dls', fn () => new TokenSessions($this->kv(), 'dl', 7200));
    }

    public function adminSessions(): TokenSessions
    {
        return $this->once('adm', fn () => new TokenSessions($this->kv(), 'adm', Config::SESSION_IDLE));
    }

    private function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }
        $r = new Router();
        $config = new ConfigApi($this);
        $up = new UploadApi($this);
        $down = new DownloadApi($this);
        $install = new InstallApi($this);
        $admin = new AdminApi($this);

        $r->add('GET', '/api/config', $config->show(...));
        $r->add('GET', '/api/install/status', $install->status(...));
        $r->add('POST', '/api/install/begin', $install->begin(...));
        $r->add('POST', '/api/install/finish', $install->finish(...));

        $r->add('POST', '/api/shares/reserve', $up->reserve(...));
        $r->add('POST', '/api/shares/{id}', $up->create(...));
        $r->add('PUT', '/api/shares/{id}/chunks/{n}', $up->putChunk(...));
        $r->add('GET', '/api/shares/{id}/upload-status', $up->status(...));
        $r->add('POST', '/api/shares/{id}/finalize', $up->finalize(...));
        $r->add('DELETE', '/api/shares/{id}', $up->abort(...));

        $r->add('GET', '/api/shares/{id}/challenge', $down->challenge(...));
        $r->add('POST', '/api/shares/{id}/unlock', $down->unlock(...));
        $r->add('GET', '/api/shares/{id}/chunks/{n}', $down->chunk(...));
        $r->add('POST', '/api/shares/{id}/complete', $down->complete(...));

        $r->add('GET', '/api/admin/salt', $admin->salt(...));
        $r->add('POST', '/api/admin/login', $admin->login(...));
        $r->add('POST', '/api/admin/logout', $admin->logout(...));
        $r->add('GET', '/api/admin/session', $admin->session(...));
        $r->add('GET', '/api/admin/shares', $admin->list(...));
        $r->add('DELETE', '/api/admin/shares/{id}', $admin->delete(...));

        return $this->router = $r;
    }

    public function handle(Request $req): Response
    {
        try {
            if (!str_starts_with($req->path, '/api/')) {
                throw new HttpException(404, 'not_found');
            }
            $res = $this->router()->dispatch($req);
        } catch (HttpException $e) {
            $res = Response::json(['error' => $e->errorCode] + $e->extra, $e->status);
            $res->headers += $e->headers;
        } catch (\RuntimeException $e) {
            error_log('locksend: ' . get_class($e) . ': ' . $e->getMessage());
            $res = Response::json(['error' => 'unavailable'], 503);
        } catch (\Throwable $e) {
            error_log('locksend: ' . get_class($e) . ': ' . $e->getMessage());
            $res = Response::json(['error' => 'internal'], 500);
        }
        $res->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];
        return $res;
    }
}
