<?php

declare(strict_types=1);

use LockSend\Api\App;
use LockSend\Support\Config;
use LockSend\Support\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

// Access logging is the web server's job; PHP must never echo errors to clients.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$config = new Config();
$request = Request::fromGlobals($config);

// The install page exists only until installation has completed.
if ($request->path === '/install') {
    if ($config->isInstalled()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "Not found\n";
        exit;
    }
    $file = $config->templatesDir . '/install.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $csp = [
        "default-src 'none'",
        "script-src 'self' 'wasm-unsafe-eval'",
        "style-src 'self'",
        "connect-src 'self'",
        "img-src 'self'",
        "base-uri 'none'",
        "form-action 'none'",
        "frame-ancestors 'none'",
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    is_file($file) ? readfile($file) : print('Not built');
    exit;
}

(new App($config))->handle($request)->send();
