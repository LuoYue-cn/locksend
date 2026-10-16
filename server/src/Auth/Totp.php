<?php

declare(strict_types=1);

namespace LockSend\Auth;

use LockSend\RateLimit\Kv;
use LockSend\Support\Base64Url;
use LockSend\Support\Secrets;
use OTPHP\TOTP as OtpTotp;

/** Admin second factor. The shared secret is encrypted at rest with a key derived from the pepper. */
final class Totp
{
    public function __construct(private readonly Secrets $secrets, private readonly Kv $kv)
    {
    }

    /** @return array{secret:string,uri:string,stored:string} */
    public function generate(string $label): array
    {
        $totp = OtpTotp::generate();
        $totp->setLabel($label);
        $totp->setIssuer('LockSend');
        return [
            'secret' => $totp->getSecret(),
            'uri' => $totp->getProvisioningUri(),
            'stored' => $this->seal($totp->getSecret()),
        ];
    }

    public function seal(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return Base64Url::encode($nonce . sodium_crypto_secretbox($secret, $nonce, $this->secrets->totpKey()));
    }

    public function open(string $stored): ?string
    {
        $raw = Base64Url::decode($stored);
        if ($raw === null || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $pt = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->secrets->totpKey()
        );
        return $pt === false ? null : $pt;
    }

    /** True when the 6-digit code is valid (±1 step) and has not been used yet. Does not consume it. */
    public function check(string $stored, string $code): bool
    {
        if (preg_match('/^\d{6}$/D', $code) !== 1) {
            return false;
        }
        $secret = $this->open($stored);
        if ($secret === null || !OtpTotp::createFromSecret($secret)->verify($code, null, 29)) {
            return false;
        }
        return $this->kv->get($this->usedKey($secret, $code)) === null;
    }

    /** Marks a code as used; false when it was already consumed (replay or concurrent login). */
    public function consume(string $stored, string $code): bool
    {
        $secret = $this->open($stored);
        return $secret !== null && $this->kv->setNx($this->usedKey($secret, $code), '1', 120);
    }

    /** check() + consume() for flows with a single factor. */
    public function verify(string $stored, string $code): bool
    {
        return $this->check($stored, $code) && $this->consume($stored, $code);
    }

    private function usedKey(string $secret, string $code): string
    {
        return 'totp:used:' . hash('sha256', $secret . $code);
    }
}
