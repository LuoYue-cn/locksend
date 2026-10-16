<?php

declare(strict_types=1);

// Housekeeping, intended to run every few minutes from cron / a systemd timer:
//  - expire shares past their deadline (stale uploads included) and delete their blobs
//  - delete tombstone rows after a short retention
//  - remove orphaned blob directories and stale temp files

use LockSend\Api\App;
use LockSend\Storage\ShareService;
use LockSend\Support\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = new Config();
if (!$config->isInstalled()) {
    exit(0);
}
$app = new App($config);
$db = $app->db();
$shares = $app->shares();
$now = time();

$expired = $db->all(
    "SELECT id FROM shares WHERE status IN ('uploading','ready','downloading') AND expires_at <= ?",
    [$now]
);
foreach ($expired as $row) {
    $shares->close((string) $row['id'], ShareService::EXPIRED);
}

// Frozen/old tombstones carry no secrets; drop them after the retention period.
$tomb = $db->exec(
    "DELETE FROM shares WHERE status IN ('burned','destroyed','expired') AND closed_at > 0 AND closed_at <= ?",
    [$now - Config::TOMBSTONE_SECONDS]
);

$orphans = 0;
foreach ($app->blobs()->listIds() as $id) {
    $row = $shares->find($id);
    if ($row === null || !ShareService::isOpen($row)) {
        $app->blobs()->deleteAll($id);
        $orphans++;
    }
}

$stale = 0;
foreach (glob($config->tmpDir() . '/*') ?: [] as $f) {
    if (is_file($f) && filemtime($f) < $now - 3600) {
        @unlink($f);
        $stale++;
    }
}

fwrite(STDOUT, sprintf(
    "expired=%d tombstones_deleted=%d orphan_dirs=%d stale_tmp=%d\n",
    count($expired),
    $tomb,
    $orphans,
    $stale
));
