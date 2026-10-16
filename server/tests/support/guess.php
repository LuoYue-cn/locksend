<?php

declare(strict_types=1);

// Child process for ConcurrencyTest: waits for a start barrier, then submits $attempts codes
// directly to ShareAuth against the shared database and prints one outcome per line.

use LockSend\Api\App;
use LockSend\RateLimit\MemoryKv;
use LockSend\Support\Base64Url;
use LockSend\Support\Config;
use LockSend\Support\HttpException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $dataDir, $shareId, $attempts, $startAt, $mode] = $argv;
$dbEnv = array_filter([
    'LOCKSEND_DB_DSN' => getenv('LOCKSEND_DB_DSN') ?: null,
    'LOCKSEND_DB_USER' => getenv('LOCKSEND_DB_USER') ?: null,
    'LOCKSEND_DB_PASS' => getenv('LOCKSEND_DB_PASS') ?: null,
]);
$config = new Config($dbEnv + [
    'LOCKSEND_DATA_DIR' => $dataDir,
    'LOCKSEND_PEPPER' => str_repeat('ab', 32),
    'LOCKSEND_REDIS' => 'tcp://127.0.0.1:1',
]);
$app = new App($config, new MemoryKv());
$auth = $mode === 'right' ? Base64Url::decode((string) file_get_contents($dataDir . '/right-auth')) : null;

while (microtime(true) < (float) $startAt) {
    usleep(200);
}
for ($i = 0; $i < (int) $attempts; $i++) {
    try {
        $app->shareAuth()->attempt($shareId, $auth ?? random_bytes(32));
        echo "ok\n";
    } catch (HttpException $e) {
        echo $e->errorCode . "\n";
    }
}
