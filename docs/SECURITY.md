# Security model and limitations

No system is "100 % secure". This document states what LockSend is designed to protect against, what it does not,
and what remains unverified. Read it together with `PROTOCOL.md`.

## What it defends against

| Threat | How |
|---|---|
| Network eavesdropping / MITM | TLS 1.3 only (`deploy/nginx/locksend.conf`); payloads are additionally end-to-end encrypted, so TLS termination at a relay or CDN would still not expose content |
| Leak of server disk, database or backups | Only ciphertext chunks and non-exploitable metadata are stored. File name, size, type are encrypted (`enc_meta`) or sealed to the admin (`sealed_name`). The DB holds `HMAC(pepper, auth)`; `auth` includes `K_link`, which the server never sees, so the DB cannot be used to test code guesses offline |
| Curious or malicious administrator | The admin interface can list `sealed_name` (decrypted only in the admin's browser with a password-wrapped private key), creation/expiry time, and delete. There is no size, IP, status history, or download counter anywhere in the admin API or UI |
| Link enumeration | 128-bit random share IDs; unknown, closed, and expired shares answer identically (`410 gone`); per-client request limits |
| Online guessing of the pick-up code | `max_fails` (3/5/10, default 5) per share enforced inside a serialised transaction; then destroy (default) or 24 h freeze; Argon2id (64 MiB, t=3) makes each offline guess costly for anyone who somehow obtained `K_link` and a verifier |
| Locating the origin by DNS/cert/headers | Relay + WireGuard layout (`deploy/relay`, `deploy/wireguard`); no outbound requests from the app; `server_tokens off`; `access_log off` |
| Third-party content / tracking | No CDN, fonts, analytics or captcha; CSP `default-src 'none'` with `'self'` only (plus `wasm-unsafe-eval` for Argon2); e2e test asserts no request leaves localhost |
| Tampering with stored ciphertext | AES-GCM per chunk with index and `is_last` bound into IV/AAD; length checks; any failure aborts |

## What it does NOT defend against (by design)

1. **A compromised server delivering modified JavaScript.** This is inherent to web-based E2EE: the code that
   encrypts is downloaded from the server. Mitigations only: SRI on every script/stylesheet, strict CSP, a
   content-hash manifest published at `/build-manifest.json` (reproducible from `web/` with `npm ci && npm run build`),
   and an independent check of those hashes by users or auditors. Someone who controls the server at the moment of
   a request can still serve a different page and a different manifest.
2. The sender or recipient leaking the link **and** the code (or posting them together).
3. A compromised endpoint: malware, hostile browser extensions, shoulder surfing.
4. **Traffic analysis**: observers see when uploads/downloads happen and roughly how large they are (chunk sizes are fixed,
   total length is visible).
5. **Deliberate destruction.** Anyone who knows a share ID (the part of the URL *before* `#`) can submit wrong codes and
   destroy the share, because the server cannot tell a guess from an attacker without `K_link`. This is the accepted
   price of "wrong codes destroy". It is a denial of service, not a disclosure. Choose `freeze_24h` if you prefer
   recoverability over deletion.

## Known limitations and operational notes

* **Download confirmation is client-asserted.** "Burn after complete" fires when the browser calls `/complete` and the
  server has served every chunk index to that download session. A malicious client can fetch all chunks and never
  confirm (the share then lives until expiry or max completions), but it cannot make the server burn early unless it
  fetched everything. A lost confirmation (browser crash after saving) leaves the share available.
* **Server-side secrets.** `server_pepper` should come from the environment (`LOCKSEND_PEPPER`). If it is generated into
  `data/secrets/pepper` (the fallback), a full server-disk compromise also yields it; the DB alone still does not.
  Losing the pepper invalidates all existing shares and the admin login.
* **TOTP secret** is encrypted at rest with a key derived from the pepper, but must be decryptable by the server to
  verify codes. Treat a full-server compromise as exposing the second factor.
* **Admin password loss is unrecoverable.** The private key is wrapped by a key derived from the password only in the
  browser; the server cannot reset it. Re-installing creates a new key pair, and older sealed names stay unreadable.
* **Visitor addresses.** The application keeps none: nginx `access_log off`, PHP-FPM `access.log = /dev/null`. Rate-limit
  buckets are an HMAC of the address under a random key held only in Redis (memory, `save ""`, expires after ≤ 2 days),
  so they cannot be reversed after expiry. Behind the relay every visitor shares the tunnel address, so those limits are
  effectively global; see `deploy/relay/README.md`.
* **Redis loss** (restart) invalidates in-flight upload/download sessions, rate counters, and admin sessions; clients
  can resume uploads (chunks are on disk) and re-unlock downloads. Shares themselves live in the database.
* **Memory / device limits.** Argon2id with 64 MiB can fail on very low-memory phones. Browsers without a usable
  service worker fall back to buffering the whole file in memory (files are limited to 500 MB).
* **SQLite deletion.** Rows are wiped (`secure_delete = ON`, secrets blanked), but deleted data can survive in old
  backups and filesystem snapshots. Ciphertext file deletion is a normal `unlink`; use an encrypted volume.
* **Metadata the server necessarily sees:** share ID, creation/expiry times, chunk count (≈ file size to within 4 MiB
  while the share exists), chosen policy (max fails, fail action, burn flag), and request timing.

## Verification status (what was and was not tested)

Tested in this repository (all re-runnable, see `docs/DEPLOY.md` section 10):

* JS crypto unit tests with fixed vectors: 17.
* PHP tests: 51, on **PHP 8.3.35** (built from the official source with FPM, `sodium`, `pdo_sqlite`, `pdo_mysql`, `intl`
  and the PECL **`redis` 6.3.0 extension**). The whole suite passes both on SQLite and on a real **MariaDB 11.4.5**
  server (every test gets its own empty database). It includes an independent recomputation of the protocol vectors
  (libsodium / `hash_hkdf` / OpenSSL); real parallel processes racing wrong guesses on one share (`ConcurrencyTest`;
  removing the transaction makes it fail on both databases); `RedisKv` against a real redis-server over a unix socket and
  TCP, through both ext-redis and the built-in client; and the 500 MB file-size limit.
* PSR-12: `vendor/bin/phpcs` reports 0 errors and 0 warnings (tests are exempt from the line-length warning only).
* Production nginx files (`scripts/check-nginx-tls.sh`): `nginx -t` passes with a throw-away self-signed certificate;
  a live handshake negotiates TLS 1.3 and **TLS 1.2 is refused**; HSTS/CSP/nosniff/COOP headers are present; no nginx
  version is exposed; HTTP redirects to HTTPS; source, vendor, data and dot-file paths return 404.
* Headless-Chromium end-to-end run, **48 checks through a real PHP-FPM 8.3 master/pool loading the shipped
  `deploy/php-fpm/locksend.conf`** (unix socket, `clear_env`, `open_basedir`, pepper from the pool's `env[]`), nginx with
  the shipped location/header/fastcgi files, and Redis over a unix socket with ext-redis. Covered: install flow and its
  lock-out; multi-chunk upload/download with SHA-256 comparison and service-worker streaming; upload interrupted after
  2 of 4 chunks and resumed without re-sending stored chunks; empty file; burn; wrong-code destruction; missing/wrong
  `K_link`; admin name decryption and delete; the 500 MB limit (shown on the page, a 500 MB + 1 byte file refused before
  any request); a **490 MB file** uploaded (117 chunks, exact ciphertext size on disk), streamed back and decrypted with
  identical SHA-256, PHP-FPM memory unchanged, ciphertext deleted after the confirmed download; no plaintext names,
  codes or keys anywhere under the data directory; no third-party requests. The same suite also passes on the simpler
  `php-cgi` stack.
* `scripts/audit_privacy.sh` static checks (verified to fail when violations are injected).

Problems these runs found and fixed: the shipped FPM pool file had an unquoted `base64:…=` pepper that makes PHP-FPM
refuse to start; `RedisKv` could not parse the `unix:///…` socket URL used by the shipped configuration; a test helper
needed `intl`, which PHP 8.3 does not enable by default.

Not tested / not claimed:

* The Docker image and `docker-compose.yml` (no Docker on the test machine), the systemd units, the relay
  (`nginx-stream.conf`), WireGuard and the nftables rules. Certificate issuance/renewal (only a self-signed certificate was used).
* Running FPM as root with the `www-data` user switch (FPM ran unprivileged, so `user`/`group` were ignored) and the real
  filesystem permissions of `/var/lib/locksend`.
* Files above 500 MB are no longer accepted. Before the limit was introduced, a 4.5 GiB upload succeeded but the browser's
  memory grew by about 3.9 GiB during it; the cause was not investigated (see QUESTIONS.md item 16).
* Resumption after a full browser restart (in-page resume is tested; a new page cannot continue an old upload because the
  upload token and keys live only in that page's memory, by design).
* Non-Chromium browsers and low-memory devices.
* No third-party security review has been done.
