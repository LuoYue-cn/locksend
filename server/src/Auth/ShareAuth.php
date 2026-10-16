<?php

declare(strict_types=1);

namespace LockSend\Auth;

use LockSend\Storage\ShareService;
use LockSend\Support\Clock;
use LockSend\Support\Config;
use LockSend\Support\Db;
use LockSend\Support\HttpException;
use LockSend\Support\Secrets;

/**
 * Pick-up-code verification and the failure policy (destroy / freeze).
 * The whole attempt runs in one write transaction, so concurrent guesses are
 * serialised and cannot exceed max_fails.
 */
final class ShareAuth
{
    public function __construct(
        private readonly Db $db,
        private readonly ShareService $shares,
        private readonly Secrets $secrets,
        private readonly Clock $clock,
    ) {
    }

    /** auth_verifier = HMAC-SHA256(server_pepper, auth), hex. */
    public function verifier(string $auth): string
    {
        return hash_hmac('sha256', $auth, $this->secrets->pepper());
    }

    /**
     * @return array<string,mixed> the share row after a successful attempt
     * @throws HttpException on any failure (410 gone/destroyed, 423 frozen, 401 bad_code)
     */
    public function attempt(string $id, string $auth): array
    {
        $now = $this->clock->now();
        $candidate = $this->verifier($auth);

        $outcome = $this->db->tx(function () use ($id, $candidate, $now): array {
            $row = $this->db->one('SELECT * FROM shares WHERE id = ?' . $this->db->forUpdate(), [$id]);
            if ($row === null || !ShareService::isOpen($row) || $row['status'] === ShareService::UPLOADING) {
                return ['gone'];
            }
            if ((int) $row['expires_at'] <= $now) {
                $this->shares->markClosed($id, ShareService::EXPIRED);
                return ['gone', true];
            }
            if ((int) $row['frozen_until'] > $now) {
                return ['frozen', (int) $row['frozen_until'] - $now];
            }
            if (hash_equals((string) $row['auth_verifier'], $candidate)) {
                $this->db->exec(
                    "UPDATE shares SET fail_count = 0, status = 'downloading' WHERE id = ?",
                    [$id]
                );
                $row['fail_count'] = 0;
                $row['status'] = ShareService::DOWNLOADING;
                return ['ok', $row];
            }
            $fails = (int) $row['fail_count'] + 1;
            $max = (int) $row['max_fails'];
            if ($fails >= $max) {
                if ($row['fail_action'] === 'freeze_24h') {
                    $this->db->exec(
                        'UPDATE shares SET fail_count = 0, frozen_until = ? WHERE id = ?',
                        [$now + Config::FREEZE_SECONDS, $id]
                    );
                    return ['frozen_now', Config::FREEZE_SECONDS];
                }
                $this->shares->markClosed($id, ShareService::DESTROYED);
                return ['destroyed', true];
            }
            $this->db->exec('UPDATE shares SET fail_count = ? WHERE id = ?', [$fails, $id]);
            return ['bad', $max - $fails];
        });

        switch ($outcome[0]) {
            case 'ok':
                return $outcome[1];
            case 'gone':
                if (($outcome[1] ?? false) === true) {
                    $this->shares->purge($id);
                }
                throw new HttpException(410, 'gone');
            case 'destroyed':
                $this->shares->purge($id);
                throw new HttpException(410, 'destroyed');
            case 'frozen':
            case 'frozen_now':
                throw new HttpException(
                    423,
                    'frozen',
                    ['retry_after' => $outcome[1]],
                    ['Retry-After' => (string) $outcome[1]]
                );
            default:
                throw new HttpException(401, 'bad_code', ['attempts_left' => $outcome[1]]);
        }
    }
}
