<?php

declare(strict_types=1);

namespace LockSend\Api;

use LockSend\Support\Base64Url;
use LockSend\Support\Config;
use LockSend\Support\Request;
use LockSend\Support\Response;

final class ConfigApi
{
    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string,string> $p */
    public function show(Request $req, array $p = []): Response
    {
        if (!$this->app->config->isInstalled()) {
            return Response::json(['installed' => false]);
        }
        $s = $this->app->settings();
        $pub = $this->app->admins()->publicKey();
        return Response::json([
            'installed' => true,
            'kdf' => Config::KDF,
            'chunk' => Config::CHUNK,
            'max_file_bytes' => $s->maxFileBytes(),
            'ttl_days' => ['default' => $s->ttlDefaultDays(), 'max' => $s->ttlMaxDays()],
            'max_fails' => ['options' => Config::MAX_FAILS_OPTIONS, 'default' => 5],
            'fail_actions' => Config::FAIL_ACTIONS,
            'admin_pub' => $pub,
        ]);
    }
}
