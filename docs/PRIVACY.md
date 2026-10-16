# Privacy

What the service stores, for how long, and what it deliberately does not.

## Stored on the server

| Data | Where | Content | Retention |
|---|---|---|---|
| Ciphertext chunks | `data/blobs/<share_id>/<n>` | AES-GCM ciphertext, fixed chunk size | until burn / destroy / delete / expiry (cleanup runs every 5 min) |
| Share row | database `shares` | share ID, status, created/expiry times, chunk count, policy (max fails, fail action, burn flag, max completions), fail counter, public KDF salt, `HMAC(pepper, auth)`, `enc_meta` (opaque), `sealed_name` (opaque) | opaque fields and verifier are blanked when closed; the empty tombstone row is deleted after 7 days |
| Admin record | database `admin` | admin public key, password-wrapped private key, wrap salt, login verifier, encrypted TOTP secret | until reinstall |
| Sessions, rate limits, download progress | Redis, memory only | hashed bearer tokens, counters, bitmaps | TTL ≤ 6 h (rate-limit key ≤ 2 days); lost on restart |

## Not stored / not visible to the server

File content, file name, MIME type, exact size, the pick-up code, `K_link`, any derived file key, the admin password and the
admin private key in clear. File names exist in plaintext only in the sender's and recipient's browsers and, after the admin
decrypts it locally, in the admin's browser.

## Visitor addresses

* nginx: `access_log off`. PHP-FPM: `access.log = /dev/null`. The application never writes a request address to disk or logs.
* Short-lived rate limiting uses `HMAC(random key held in Redis, address)`, truncated; it exists only in memory and expires (≤ 2 days).
* Behind the optional relay, the origin never sees visitor addresses at all; the relay's nginx is also configured with `access_log off`.
* Your hosting provider, upstream network, and the relay operator can still observe connections; this is outside the application's control.

## Third parties

None at runtime: no CDN, web fonts, analytics, captcha, or remote scripts. All assets are served from the same origin with SRI;
`Referrer-Policy: no-referrer`. The end-to-end test asserts that the pages make no request to any other host.

## Cookies and browser storage

One cookie, `ls_admin` (HttpOnly, SameSite=Strict, `/api/admin` only, set after admin login). Visitors get no cookies. Nothing is written
to `localStorage`/`sessionStorage`/IndexedDB. The pick-up code is not placed in URLs, cookies or storage. Downloads are saved through the browser's save-file dialog where available; otherwise a service worker is registered
only to stream a download to disk; it caches nothing.

## Verify it yourself

`scripts/audit_privacy.sh [DATA_DIR]` checks the shipped configuration and source for logging directives, external URLs, and
(if a data directory is given) plaintext leakage. It is a helper, not a proof.
