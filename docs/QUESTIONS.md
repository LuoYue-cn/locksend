# Open questions and decisions taken

Where the specification was silent, ambiguous or conflicting, the safer option below was chosen and work continued.
Each item can be revisited by the operator.

1. **Spec truncated after the `shares` table (section 4), and it refers to a "section 14".**
   The task text ends mid-schema (`status ... expir`) and section 14 was not provided. Decision: `status` values are
   `uploading | ready | downloading | burned | destroyed | expired`; the rest of the schema is in
   `server/migrations/001_init.sql`. The threat-model/limitations content that "section 14" presumably meant is in
   `docs/SECURITY.md`.
2. **PHP version.** The header says PHP 8.1, the stack section says 8.3. Decision: `composer.json` requires `>=8.3`, code
   uses `declare(strict_types=1)`. Only a PHP 8.5 CLI was available for testing, so FPM/8.3 are untested.
3. **Install flow.** Not specified beyond "an install page that closes itself". Decision: a one-time setup token is created
   on the server (`php server/bin/admin-setup-token.php`, only its SHA-256 is stored); the install page checks the
   environment, takes the token, sets the admin password (processed in the browser), and enrols TOTP. On success
   `data/installed.lock` is written, the token is deleted, `/install` returns 404 and all install API routes return 404.
   Without a token no one can install, so a stranger who reaches a fresh server first cannot take it over.
4. **Admin authentication.** Not specified. Decision: password (Argon2id in the browser, only an independent HKDF output
   is sent) plus TOTP (`spomky-labs/otphp`), HttpOnly SameSite=Strict cookie, `X-LockSend: 1` header on mutating calls,
   login rate-limited, TOTP codes single-use.
5. **Destroying by share ID alone.** Because the server cannot tell who is guessing, anyone with the URL path can burn
   attempts (see SECURITY.md). Accepted per the task ("deliberate wrong code is a protective outcome"). `freeze_24h` is the alternative.
6. **Visitor IP and rate limiting.** "Do not log IPs" and "rate-limit guessing" conflict. Decision: per-share failure
   counting does not use IPs at all; coarse per-client request limits use an HMAC of the address with a random key kept
   only in Redis memory (≤ 2 days). Behind a relay all visitors look alike, so these degrade to global limits.
7. **Download confirmation.** "Client confirms completion" can be forged. Decision: the server only accepts it after it
   has served every chunk index to that session (tracked in a Redis bitmap), and reports honestly in SECURITY.md that
   it remains client-asserted.
8. **`max_completions`.** Default 1 and stored per share; the UI does not expose other values (the API accepts 1–100).
9. **Admin-visible data.** Spec allows file name and delete only. Decision: list shows decrypted name, share ID and the
   two dates. It does not show size, IP, status or counts. `chunk_count` is stored (needed for serving) but not exposed.
10. **Tombstones.** Closed shares keep a row with secrets blanked for 7 days (so the answer stays `410`), then are deleted by `bin/cleanup.php`.
11. **Saving downloads.** Three ways, tried in order: (1) the browser's save-file dialog (File System Access API,
    Chromium-based browsers), which writes decrypted chunks straight into the chosen file; (2) a streamed download through
    the service worker (`/sw.js`, `/dl/<random key>`), constant memory; (3) an in-memory Blob (acceptable because files are
    limited to 500 MB). A reported bug ("download cancelled" and a 404 in the browser's download list) happens when a
    download-manager extension or the browser re-requests `/dl/<key>` from the server, which never has that URL. The page
    now notices when the browser does not start reading the streamed download within a few seconds, or cancels it, and
    switches to the next method automatically. Closing the save dialog stops without starting a second download. On phones and in-app (WebView) browsers the save dialog is often present but broken (it rejects immediately as "cancelled"), so on mobile the page decrypts into memory and shows a 保存文件 button that saves the file from a fresh tap; on desktop an AbortError that arrives within 0.6 s is treated as "dialog never appeared" and the next method is used.
12. **zxcvbn.** Required for custom codes, but it is ~800 KB. Decision: a separate content-hashed file loaded lazily with SRI
    only when a custom code is typed, so the default page stays small.
13. **`wasm-unsafe-eval` in CSP.** Needed to compile the Argon2 WASM module. No `unsafe-eval` or `unsafe-inline` is allowed.
14. **Deployment targets.** Docker, FPM, TLS and WireGuard files are provided but could not be executed in the build sandbox (see SECURITY.md).
15. **Language.** The UI is in Chinese (the task language); strings are inline in `web/src/` and not yet externalised.
16. **File size limit (changed by the product owner).** The original decision was "no limit on file size". After a
    4.5 GiB browser test showed the upload page's memory growing with file size (about +3.9 GiB in Chromium), the owner
    decided on a **500 MB per-file limit** instead of chasing that issue. Implementation: the server cannot see the
    plaintext size, so it enforces the limit as a chunk-count cap, `chunk_count <= ceil(LOCKSEND_MAX_FILE_BYTES / 4 MiB)`
    (120 chunks for the default 500 000 000 bytes), answering `413 file_too_large`; extra chunk indices are already
    refused. The upload page reads `max_file_bytes` from `/api/config`, shows it, and refuses an oversized file before
    contacting the server. A modified client could still declare a smaller file inside the same 120 chunks, which is
    harmless: the stored ciphertext can never exceed 120 x (4 MiB + 16 B). Chunked encryption, resumable upload and
    streaming download are unchanged. The memory growth seen above 500 MB was not investigated further.
