# LockSend protocol (version 1)

All cryptography runs in the browser with WebCrypto plus a pinned Argon2id WASM build. No custom primitives.
The reference implementation is `web/src/crypto/`; fixed vectors are in `web/tests/vectors.json` and are
recomputed independently in PHP (libsodium, `hash_hkdf`, OpenSSL) by `server/tests/VectorsTest.php`.

## 1. Constants

| Name | Value |
|---|---|
| `CHUNK` (plaintext chunk) | 4 194 304 bytes (4 MiB) |
| `TAG` | 16 bytes (AES-GCM) |
| `CCHUNK` (ciphertext chunk) | `CHUNK + 16` = 4 194 320. Every chunk except the last has exactly this size |
| Empty file | `chunk_count = 1`, the single chunk is the 16-byte tag over an empty plaintext |
| Argon2id, `kdf_v = 1` | `m = 65536 KiB (64 MiB)`, `t = 3`, `p = 1`, output 32 bytes. Fixed server-side, served by `GET /api/config` and `GET /api/shares/{id}/challenge`. Clients never choose them, and **refuse** parameters weaker than these |
| `share_id` | `base64url(random_bytes(16))`, 22 characters, issued by the server |
| `salt` | 16 random bytes, public, stored by the server |
| `K_link` | 32 random bytes, only ever in the URL fragment (`#…`, base64url, 43 chars) |

## 2. Pick-up code

* EFF Large Wordlist (7776 words, SHA-256 of the source file `addd35536511597a02fa0a9ff1e5284677b8883b83e986e43f15a3db996b903e`,
  kept in `web/vendor-lock/`), generated into `web/src/crypto/wordlist-eff.js`.
* Default 4 words, high-security option 6 words, joined with `-` (`river-amber-lunar-fox`).
* Word choice uses `crypto.getRandomValues` with **rejection sampling** over 32-bit values
  (`limit = floor(2^32 / n) * n`; draws `>= limit` are discarded) – no modulo bias.
* Normalisation before use: `trim → NFKC → lowercase → runs of whitespace/underscore → "-"`.
* Custom codes (advanced) must be ≥ 12 characters after normalisation and score ≥ 4 in zxcvbn 4.4.2
  (loaded on demand as a separate SRI-pinned file).
* The code is never put in a URL, cookie, `localStorage`, request, or log.

Entropy: 4 words ≈ 51.7 bits, 6 words ≈ 77.5 bits. The code alone is **not** the secret that protects the file
against an offline attacker; see `K_link` below.

## 3. Key derivation

```
share_id_bytes = base64url_decode(share_id)                      # 16 bytes
M       = Argon2id(normalize(code), salt, kdf_v1)                # 32 bytes
ikm     = M || K_link                                            # 64 bytes
auth    = HKDF-SHA256(ikm, salt = share_id_bytes, info = "share-auth-v1",   L = 32)   # sent to server
K_file  = HKDF-SHA256(ikm, salt = share_id_bytes, info = "file-master-v1",  L = 32)
K_data  = HKDF-SHA256(K_file, salt = "" (empty),  info = "data-v1",          L = 32)
K_meta  = HKDF-SHA256(K_file, salt = "" (empty),  info = "meta-v1",          L = 32)
```

The server stores `auth_verifier = hex(HMAC-SHA256(server_pepper, auth))` and compares with `hash_equals`.
`server_pepper` (≥ 32 bytes) comes from `LOCKSEND_PEPPER` or, if unset, a `0600` file under `data/secrets/` – never
the database. Because `auth` includes `K_link`, a database leak alone cannot be used to test code guesses offline.
`K_link`, `code`, `M`, `K_data`, `K_meta` never leave the browser.

## 4. Chunk encryption

```
iv_i   = 0x00000000 || uint64_be(i)                       # 12 bytes
aad_i  = "v1" || share_id_bytes(16) || uint32_be(i) || uint8(is_last)
C_i    = AES-256-GCM(K_data, iv_i, P_i, aad_i)            # ciphertext || 16-byte tag
```

The downloader must: check `chunk_count` against the decrypted metadata; check every ciphertext length
(`CCHUNK` for non-final chunks, `plain_len + 16` for the final one); decrypt chunk *i* with `is_last = 1` only for the
last index; abort on the first authentication failure; and check the total plaintext length equals `meta.size`.
Re-ordering, truncation, duplication, or swapping chunks between shares changes `iv`/`aad` and fails authentication.

## 5. Metadata

```
meta     = JSON { name, size, mime, chunk_size, chunk_count, v: 1 }
enc_meta = iv(12 random) || AES-256-GCM(K_meta, iv, meta, aad = "meta-v1" || share_id_bytes)
```

`chunk_size` must equal 4 194 304 and `chunk_count` must equal `max(1, ceil(size / CHUNK))`; otherwise the client rejects it.
`enc_meta` is returned by the server only after a successful `unlock`.

## 6. File name for the administrator (ECIES, P-256)

```
(e, E)      = fresh ECDH P-256 key pair per upload
secret      = ECDH(e, admin_pub)                                  # 32 bytes
key         = HKDF-SHA256(secret, salt = E (65 bytes), info = "name-seal-v1", L = 32)
sealed_name = E(65, uncompressed) || iv(12) || AES-256-GCM(key, iv, name_utf8, aad = "name-seal-v1")
```

The admin key pair is generated in the **admin's browser at install time**. The private key (PKCS#8) is stored
only as `iv || AES-256-GCM(wrapKey, pkcs8, aad = "admin-wrap-v1")`.

## 7. Administrator password

```
M2          = Argon2id(NFKC(password), wrap_salt, kdf_v1)
login_secret = HKDF-SHA256(M2, salt = "", info = "admin-login-v1", L = 32)   # sent to server (verified as HMAC(pepper, ·))
wrapKey      = HKDF-SHA256(M2, salt = "", info = "admin-wrap-v1",  L = 32)   # never leaves the browser
```

Login additionally requires a TOTP code. The two HKDF outputs are independent, so the login secret does not help
recover `wrapKey`. The server cannot decrypt file names; a forgotten password makes existing sealed names unreadable.

## 8. HTTP API (summary)

All bodies are JSON unless noted; errors are `{"error": "<code>"}`. Bearer tokens are 32 random bytes (base64url),
stored only as SHA-256 in Redis.

| Method & path | Purpose |
|---|---|
| `GET /api/config` | KDF params, TTL limits, `max_file_bytes`, options, `admin_pub` |
| `POST /api/shares/reserve` | issue `share_id` + upload token (rate limited) |
| `POST /api/shares/{id}` | register share (`413 file_too_large` when `chunk_count` exceeds `ceil(max_file_bytes / CHUNK)`, 120 by default): `kdf_v, salt, auth, enc_meta, sealed_name, chunk_count, max_fails∈{3,5,10}, fail_action∈{destroy,freeze_24h}, ttl_days (1…max), burn_after_complete, max_completions` |
| `PUT /api/shares/{id}/chunks/{n}` | upload ciphertext chunk (idempotent; size-checked) |
| `GET /api/shares/{id}/upload-status` | received ranges, for resume |
| `POST /api/shares/{id}/finalize` | verify every chunk present, set `ready` |
| `DELETE /api/shares/{id}` | abort an unfinished upload |
| `GET /api/shares/{id}/challenge` | `{salt, kdf}` – identical `410` for unknown / closed / expired |
| `POST /api/shares/{id}/unlock` | `{auth}` → `{download_token, enc_meta, chunk_count, expires_at}` or `401 bad_code` / `410` / `423` |
| `GET /api/shares/{id}/chunks/{n}` | ciphertext chunk (bearer download token); nginx serves the file via `X-Accel-Redirect` |
| `POST /api/shares/{id}/complete` | client confirms the download; server checks all chunks were served, then burns if configured |
| `GET /api/admin/salt`, `POST /api/admin/login`, `GET /api/admin/shares`, `DELETE /api/admin/shares/{id}`, `POST /api/admin/logout` | admin |
| `GET /api/install/status`, `POST /api/install/begin`, `POST /api/install/finish` | first-run install (404 after installation) |

### Wrong-code policy

`unlock` runs in one write transaction (`BEGIN IMMEDIATE`), so concurrent guesses are serialised and can never exceed
`max_fails`. On the *n*-th consecutive failure: `destroy` deletes ciphertext and wipes `enc_meta`, `sealed_name`, `salt`,
`auth_verifier` (status `destroyed`); `freeze_24h` rejects all attempts, including correct ones, for 24 hours and then
resets the counter. A correct code resets the counter.

## 9. Test vectors

Inputs: `share_id = AQIDBAUGBwgJCgsMDQ4PEA` (bytes `01..10`), `K_link = a0a1…bf`, `salt = 3031…3f`,
code typed as `River Amber_lunar-fox` (normalises to `river-amber-lunar-fox`), `kdf_v1`.

```
auth    = 91b15d2de2935fc787d37a96239aecb15e26faaa384d7cedde4050a3f4105948
K_data  = a3db3cec955cc417748a079547f2e6c048aecd1b91f912ca33b45db2def10dea
K_meta  = 8d12e1a7205c495adf55b9bdde86c088dad91752372eb3a596ed7b0f8d609195

chunk 0 (not last)  plain  68656c6c6f2c206c6f636b73656e64  ("hello, locksend")
  iv     000000000000000000000000
  aad    76310102030405060708090a0b0c0d0e0f100000000000
  cipher fafa6b40504c102ac77bc7d2e8765de730abd97639d6fb892a753171295e35   (plaintext || tag, 31 bytes)

chunk 1 (last)      plain  7461696c  ("tail")
  iv     000000000000000000000001
  aad    76310102030405060708090a0b0c0d0e0f100000000101
  cipher ff2d492bc8aced781010fffbdcbf6c9e095779d6

empty file (index 0, last)
  iv     000000000000000000000000
  aad    76310102030405060708090a0b0c0d0e0f100000000001
  cipher 1482ae3f630e8172f2076eab4351b6ed

iv for chunk index 2^32 + 2 = 000000000000000100000002

meta_json = {"name":"report.pdf","size":15,"mime":"application/pdf","chunk_size":4194304,"chunk_count":1,"v":1}
meta_iv   = 707172737475767778797a7b
enc_meta  = 707172737475767778797a7b4fd0eeedd5bd0f1c392ae2d942388bb9c7dcaa4b4f1732014682312b3164cbf33902ee31545dfc3dbe52b0190097b46e7d95435fc35db488ac7ce2cdd418c7a71aa3fe8736c72dc4857e80c7e8efa0faec23e89252e918a5a83529db0f0ee40c5437d57c43d5e9268596f64f62b723d241f4bd

HKDF-SHA256 RFC 5869 test case 1 (sanity check of the HKDF primitive)
  okm = 3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865
```

Regenerate with `node web/tests/gen-vectors.mjs`; verify with `npm test` (web) and `vendor/bin/phpunit` (server).
