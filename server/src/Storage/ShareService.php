<?php

declare(strict_types=1);

namespace LockSend\Storage;

use LockSend\Support\Clock;
use LockSend\Support\Db;

/** Share lifecycle: status values and irreversible closing (burn / destroy / expire). */
final class ShareService
{
    public const UPLOADING = 'uploading';
    public const READY = 'ready';
    public const DOWNLOADING = 'downloading';
    public const BURNED = 'burned';
    public const DESTROYED = 'destroyed';
    public const EXPIRED = 'expired';

    public function __construct(
        private readonly Db $db,
        private readonly BlobStore $blobs,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->one('SELECT * FROM shares WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $row */
    public static function isOpen(array $row): bool
    {
        return in_array($row['status'], [self::UPLOADING, self::READY, self::DOWNLOADING], true);
    }

    /** @param array<string,mixed> $row */
    public static function isDownloadable(array $row, int $now): bool
    {
        return in_array($row['status'], [self::READY, self::DOWNLOADING], true) && (int) $row['expires_at'] > $now;
    }

    /**
     * Database half of closing a share: drops everything sensitive and keeps only a
     * short-lived tombstone. Safe inside a transaction; call purge() after commit.
     */
    public function markClosed(string $id, string $status): bool
    {
        $n = $this->db->exec(
            "UPDATE shares SET status = ?, closed_at = ?, enc_meta = '', sealed_name = '', salt = '',"
            . " auth_verifier = '', upload_token_hash = '' WHERE id = ?"
            . " AND status IN ('uploading','ready','downloading')",
            [$status, $this->clock->now(), $id]
        );
        return $n > 0;
    }

    public function purge(string $id): void
    {
        $this->blobs->deleteAll($id);
    }

    public function close(string $id, string $status): bool
    {
        $changed = $this->db->tx(fn () => $this->markClosed($id, $status));
        $this->purge($id);
        return $changed;
    }
}
