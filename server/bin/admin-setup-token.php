<?php

declare(strict_types=1);

// Creates the one-time setup token that authorises the /install page.
// The token is printed once; only its SHA-256 is stored (data/setup-token.sha256).

use LockSend\Support\Base64Url;
use LockSend\Support\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = new Config();
if ($config->isInstalled()) {
    fwrite(STDERR, "Already installed; refusing to create a setup token.\n");
    exit(1);
}
$config->ensureDirs();
$token = Base64Url::encode(random_bytes(24));
$file = $config->setupTokenFile();
file_put_contents($file, hash('sha256', $token) . "\n");
chmod($file, 0640);
fwrite(STDOUT, "Setup token (valid until installation completes):\n\n  {$token}\n\nOpen /install and enter it.\n");
