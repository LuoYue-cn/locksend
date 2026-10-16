# LockSend

Anonymous file sharing with browser-side end-to-end encryption (files up to 500 MB by default). The server stores only ciphertext. Downloading needs both the
link (its `#` part holds a 256-bit key that is never sent) and a generated pick-up code (4 or 6 words). Too many wrong codes
destroy the share; by default a share is destroyed after one confirmed download.

This is not an absolute-security product. Read [docs/SECURITY.md](docs/SECURITY.md) for the threat model, what is not defended
against, and what has and has not been tested.

* Server: PHP (no framework), Nginx, SQLite (WAL), memory-only Redis. Front end: plain ES modules, built with esbuild, one hashed bundle per page with SRI.
* Crypto: WebCrypto (AES-256-GCM, HKDF-SHA256, ECDH P-256) and Argon2id (WASM, 64 MiB, t=3). See [docs/PROTOCOL.md](docs/PROTOCOL.md) (with test vectors).
* First run: open `/install` (needs a one-time token from `server/bin/admin-setup-token.php`). The page closes itself when installation completes.

```
docs/     SECURITY PROTOCOL DEPLOY PRIVACY ABUSE QUESTIONS DEPENDENCIES
server/   public/ (entry index.php + built assets)  src/  migrations/  bin/  tests/  templates/ (built install page)
web/      src/ (crypto, upload, download, admin, install, sw)  tests/  build.mjs
deploy/   nginx  php-fpm  redis  relay  wireguard  systemd
scripts/  audit_privacy.sh  e2e/
```

## Quick start (development)

```sh
cd web && npm ci && npm run build && npm test        # JS crypto tests (fixed vectors)
cd ../server && composer install && vendor/bin/phpunit && vendor/bin/phpcs
bash ../scripts/check-nginx-tls.sh                    # nginx -t and TLS 1.3-only handshake check of deploy/nginx
PW_PATH=/path/to/playwright-core CHROME=/path/to/chromium bash ../scripts/e2e/run.sh   # throwaway nginx+php+redis, full browser run
bash ../scripts/audit_privacy.sh
```

Deployment: [docs/DEPLOY.md](docs/DEPLOY.md).

The front end is committed in built form (`server/public/`, `server/templates/`) so the server can run without Node; rebuild it with
`cd web && npm ci && npm run build`. Package an upload-ready server folder with `scripts/package.sh`.

## Status

Written and tested by one person with automated help; **it has not had an independent security review.** Read
[docs/SECURITY.md](docs/SECURITY.md) first: it lists what is protected, what is not, and exactly what was and was not tested.
Please report security problems privately (GitHub "Report a vulnerability") rather than in a public issue.

## License

[MIT](LICENSE)
