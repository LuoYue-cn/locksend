<?php

declare(strict_types=1);

namespace LockSend\Admin;

use LockSend\Support\Clock;
use LockSend\Support\Db;

final class AdminRepository
{
    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /** @return array<string,mixed>|null */
    public function get(): ?array
    {
        return $this->db->one('SELECT * FROM admin WHERE id = 1');
    }

    public function save(
        string $pub,
        string $wrappedPriv,
        string $wrapSalt,
        string $loginVerifier,
        string $totpEnc
    ): void {
        $this->db->tx(function () use ($pub, $wrappedPriv, $wrapSalt, $loginVerifier, $totpEnc): void {
            $this->db->exec('DELETE FROM admin WHERE id = 1');
            $this->db->exec(
                'INSERT INTO admin (id, pub, wrapped_priv, wrap_salt, login_verifier, totp_secret_enc, created_at)'
                . ' VALUES (1, ?, ?, ?, ?, ?, ?)',
                [$pub, $wrappedPriv, $wrapSalt, $loginVerifier, $totpEnc, $this->clock->now()]
            );
        });
    }

    public function publicKey(): ?string
    {
        $a = $this->get();
        return $a === null ? null : (string) $a['pub'];
    }
}
