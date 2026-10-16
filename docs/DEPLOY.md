# Deployment

Target: Debian/Ubuntu, nginx, PHP 8.3-FPM (`sodium`, `pdo_sqlite`, `redis` recommended), Redis, optional relay + WireGuard.
Only the commands under "Local test" were actually executed while building this project; everything else is provided
configuration that you must review and test in your environment (see `SECURITY.md`, "Verification status").

## 1. Build

```sh
cd web    && npm ci && npm run build      # writes server/public/*.html, assets/, sw.js, build-manifest.json, server/templates/install.html
cd ../server && composer install --no-dev --optimize-autoloader
```

Keep `server/public/build-manifest.json` (SHA-256 of every shipped file). Publishing it, and having someone rebuild
from source and compare, is the main mitigation for a tampered front end.

## 2. Layout on the origin

```
/var/www/locksend/server/{public,src,bin,migrations,templates,vendor}   # code, read-only for www-data
/var/lib/locksend/                                                      # data: sqlite, blobs/, tmp/, secrets/ – www-data only, mode 0750, outside the web root
/etc/locksend/env                                                       # optional EnvironmentFile for the cleanup timer
```

```sh
install -d -o www-data -g www-data -m 0750 /var/lib/locksend
```

nginx workers must be able to read `/var/lib/locksend/blobs` (served through `X-Accel-Redirect`); the default
`user www-data;` satisfies this. Use an encrypted filesystem for `/var/lib/locksend`.

## 3. Secrets

Generate the pepper once and keep it out of the database and the repository:

```sh
echo "base64:$(openssl rand -base64 32)"
```

Put it in `deploy/php-fpm/locksend.conf` as `env[LOCKSEND_PEPPER] = "base64:…"` (**keep the quotes**: unquoted `=` padding makes PHP-FPM refuse to start; file mode `0640 root:www-data`) and in `/etc/locksend/env`
for the cleanup timer. If unset, the installer generates `data/secrets/pepper` as a fallback (weaker: see `SECURITY.md`).
Back the pepper up separately; without it every share and the admin login become unusable.

## 4. Services

| File | Install to |
|---|---|
| `deploy/php-fpm/locksend.conf` | `/etc/php/8.3/fpm/pool.d/` (disable the default `www` pool) |
| `deploy/redis/redis.conf` | `/etc/locksend/redis.conf`; `deploy/systemd/locksend-redis.service` (or point the packaged Redis at the same settings). Must stay `save ""` + `appendonly no` |
| `deploy/nginx/locksend.conf` | include in `http {}`; also copy `locksend-locations.conf`, `locksend-headers.conf`, `locksend-fastcgi.conf` to `/etc/nginx/` |
| `deploy/systemd/locksend-cleanup.{service,timer}` | `/etc/systemd/system/`; `systemctl enable --now locksend-cleanup.timer` |

TLS: obtain a certificate (DNS-01 recommended so no public port 80 is needed on the origin) and set
`ssl_certificate*` in `locksend.conf`. It accepts **TLS 1.3 only**. `access_log off` is intentional; do not re-enable it.

## 5. First-run install

1. Start everything, then on the server: `sudo -u www-data php /var/www/locksend/server/bin/admin-setup-token.php` – prints a one-time token.
2. Open `https://<domain>/install`. The page runs environment checks (sodium, DB driver, data dir, Redis, secret).
3. Enter the token, choose an admin password (≥ 12 characters, processed in the browser), scan the TOTP secret, enter a code.
4. On success the server writes `data/installed.lock`, deletes the token, and `/install` plus all install APIs return 404 from then on.
   To re-install on purpose you must delete `installed.lock` and the database yourself, on the server.

## 6. Relay / hidden origin (optional but recommended)

See `deploy/relay/README.md`, `deploy/relay/nginx-stream.conf`, `deploy/wireguard/`. In short: DNS points to the relay only; the relay
forwards raw TCP 80/443 to the origin over WireGuard; the origin's firewall (`deploy/wireguard/origin-firewall.nft`) accepts only the tunnel.
Run `scripts/audit_privacy.sh` after deployment and confirm with an external scan that the origin does not answer publicly.

## 7. Docker (reference)

`docker-compose.yml` builds the PHP-FPM image (`server/Dockerfile`) and runs nginx (plain HTTP on 127.0.0.1:8080), PHP and a
memory-only Redis. Put your TLS 1.3 terminator in front of it. **Not executed during development** (no Docker in the build
environment); treat as a starting point. `LOCKSEND_PEPPER` is required.

## 8. Switching to MariaDB

Set `LOCKSEND_DB_DSN=mysql:host=…;dbname=locksend;charset=utf8mb4`, `LOCKSEND_DB_USER`, `LOCKSEND_DB_PASS`, and install `pdo_mysql`.
Schema and queries are portable (binary values are stored as base64url text; `Db::forUpdate()` adds `FOR UPDATE`).
Not tested; run the PHPUnit suite against a scratch MariaDB before relying on it.

## 9. Configuration reference (environment)

| Variable | Default | Meaning |
|---|---|---|
| `LOCKSEND_DATA_DIR` | `server/data` | sqlite, blobs, tmp, secrets, install lock |
| `LOCKSEND_PEPPER` | – | ≥ 32 bytes; `base64:…`, hex, or raw |
| `LOCKSEND_REDIS` | `tcp://127.0.0.1:6379` | `unix:///path` also accepted |
| `LOCKSEND_ACCEL_PREFIX` | empty | set to `/_blobs` to let nginx stream chunks; empty = PHP streams |
| `LOCKSEND_DB_DSN/USER/PASS` | sqlite file in data dir | |
| `LOCKSEND_TTL_MAX_DAYS`, `LOCKSEND_TTL_DEFAULT_DAYS` | 30, 7 | share lifetime limits |
| `LOCKSEND_CREATE_PER_HOUR` | 20 | new uploads per client bucket per hour |
| `LOCKSEND_MIN_FREE_BYTES` | 512 MiB | refuse uploads below this free space |
| `LOCKSEND_MAX_FILE_BYTES` | 500000000 (500 MB) | largest file accepted; enforced as a chunk-count cap |
| `LOCKSEND_TRUST_REAL_IP` | 0 | only used for rate-limit buckets, never logged |

## 10. Local test (what was run)

```sh
# needs php 8.3 (sodium, pdo_sqlite, pdo_mysql, intl, redis ext), php-fpm, nginx, redis-server, node, composer,
# openssl, python3, playwright-core + a Chromium binary; MariaDB only for the optional DB run
cd web && npm ci && npm test && cd ..
cd server && composer install && vendor/bin/phpunit && vendor/bin/phpcs && cd ..
LOCKSEND_TEST_DB_DSN="mysql:host=127.0.0.1;port=3306" LOCKSEND_TEST_DB_USER=... LOCKSEND_TEST_DB_PASS=... \
  server/vendor/bin/phpunit -c server/phpunit.xml        # same suite on MariaDB; the user needs CREATE/DROP on locksend_test%
bash scripts/check-nginx-tls.sh                          # nginx -t + live TLS 1.3-only check of deploy/nginx
bash scripts/audit_privacy.sh
export PW_PATH=/path/to/node_modules/playwright-core CHROME=/path/to/chrome
STACK_SCRIPT=start-fpm-stack.sh bash scripts/e2e/run.sh  # nginx + php-fpm (shipped pool file) + redis via unix sockets
STACK_SCRIPT=start-fpm-stack.sh BIG=1 bash scripts/e2e/run.sh   # adds the 490 MB upload/download case
bash scripts/e2e/run.sh                                  # same tests on a php-cgi stack
```

Everything runs under `/tmp/ls-run` and is removed afterwards.
