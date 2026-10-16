# Dependencies

All versions are pinned by lock files (`server/composer.lock`, `web/package-lock.json`). Nothing is fetched at runtime.

## Runtime (server, Composer)

| Package | Version | Why |
|---|---|---|
| `spomky-labs/otphp` | 11.5.0 | Admin TOTP (RFC 6238). Explicitly allowed by the specification |
| `paragonie/constant_time_encoding` | 3.1.3 | Transitive dependency of `otphp` (constant-time base32) |
| `symfony/deprecation-contracts`, `psr/clock` | locked | Transitive dependencies of `otphp` |

PHP extensions: `sodium` (secretbox for the TOTP secret at rest), `pdo_sqlite` (or `pdo_mysql`), `openssl`. `redis` is recommended; if it is absent a
~100-line built-in RESP client over a stream socket is used. No framework: routing, request/response handling, DB wrapper and migrations are in `server/src/Support`.

## Runtime (browser, bundled into the page scripts)

| Package | Version | Why | Integrity |
|---|---|---|---|
| `argon2-browser` | 1.18.0 | Argon2id (reference C implementation compiled to WASM). WebCrypto has no Argon2 | `dist/argon2.wasm` SHA-256 `0c2149886c13e4eae4a6ca25ee71d47423c5c8740a874cf04ff816d1b2c901d7`; embedded in each page bundle, so covered by that bundle's SRI hash; also listed in `build-manifest.json` |
| `zxcvbn` | 4.4.2 | Strength check for user-chosen codes (required by the spec). Loaded lazily from a separate hashed file, only when the custom-code field is used | `dist/zxcvbn.js` SHA-256 `f42c651f40506acb6b662490f338dd47a5951d3312039c4ab8fe5090484f351a` |
| EFF Large Wordlist | 2016-07-18 | Pick-up code words | file SHA-256 `addd35536511597a02fa0a9ff1e5284677b8883b83e986e43f15a3db996b903e` (kept in `web/vendor-lock/`) |

Everything else uses WebCrypto (AES-GCM, HKDF, ECDH, SHA) provided by the browser.

## Build and test only (not shipped, not run in production)

| Package | Version | Why |
|---|---|---|
| `esbuild` | 0.25.10 | Bundles ES modules, hashes output, enables SRI injection in `web/build.mjs` |
| `phpunit/phpunit` | 11.5.x | Server tests (`require-dev`) |
| `squizlabs/php_codesniffer` | 3.x | PSR-12 check, `vendor/bin/phpcs` (`require-dev`) |
| `playwright-core` | 1.57.0 | Used by `scripts/e2e/e2e.mjs` only; not a dependency of the repository (installed separately, together with a Chromium binary) |

Node is used only at build time; the production system has no Node process.
