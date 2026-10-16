# Abuse handling

[中文](zh-CN/ABUSE.md)

An anonymous, end-to-end encrypted service cannot inspect content, so abuse handling relies on limits and on the operator's ability to delete.
Decide your policy and legal position before opening the service to the public.

## What the operator can and cannot do

* **Can:** list active shares (decrypted file names, share IDs, dates) in `/admin`, and delete any share. Deletion removes the ciphertext
  and blanks the metadata immediately; the share then answers `410` like any unknown share.
* **Cannot:** read content, see sizes, uploader/downloader addresses, or download history. There is no data to hand over beyond share
  ID, timestamps, chunk count and policy flags. State this honestly in your terms.
* A reporter who has a complete link and code can send it to the operator; the operator can then decrypt and judge the file
  themselves, with the reporter's consent, using the normal download page.

## Built-in limits

| Control | Default | Where |
|---|---|---|
| New uploads per client bucket | 20 / hour | `LOCKSEND_CREATE_PER_HOUR`, `UploadApi::reserve` |
| Request rate on `/api/` | 30 r/s, burst 60 (per address, nginx memory) | `deploy/nginx/locksend.conf` |
| Maximum lifetime | 30 days | `LOCKSEND_TTL_MAX_DAYS` |
| Stale unfinished uploads | removed after 24 h | `bin/cleanup.php` |
| Disk protection | uploads refused when free space < 512 MiB | `LOCKSEND_MIN_FREE_BYTES` |
| Chunk size | exactly 4 MiB (+16), validated server-side | `UploadApi::putChunk` |
| File size | 500 MB (enforced as at most 120 chunks; `413 file_too_large`) | `LOCKSEND_MAX_FILE_BYTES`, `UploadApi::create` |
| Admin login | 8 attempts / 15 min per bucket, 40 / 15 min global, TOTP single-use | `AdminApi::login` |

Single files are limited to **500 MB** by default (`LOCKSEND_MAX_FILE_BYTES`; see QUESTIONS.md item 16). Combine this with the disk guard and a filesystem quota, and consider a lower `LOCKSEND_TTL_MAX_DAYS`
and a stricter `LOCKSEND_CREATE_PER_HOUR` for public deployments. Behind a relay all clients share one bucket, so per-client limits become global.

## Suggested procedure

1. Receive a report (an address you publish; do not collect more than needed).
2. If the reporter supplies link + code, download the file in a disposable browser profile; otherwise judge by the file name shown in `/admin`.
3. Delete the share in `/admin` if it violates your policy; record only the share ID and the date.
4. If a legal request arrives, respond with what exists (see `PRIVACY.md`); do not claim abilities the system does not have.

## Not provided

No automatic content scanning (impossible under E2EE), no user accounts, no per-uploader blocklists (no identity is available), no captcha (no third-party services allowed).
