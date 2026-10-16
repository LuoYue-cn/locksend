#!/usr/bin/env bash
# Static privacy audit of the shipped configuration and sources.
# Usage: scripts/audit_privacy.sh [DATA_DIR]
# Exits non-zero when a check fails. A helper, not a proof: see docs/SECURITY.md.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DATA_DIR="${1:-}"
fail=0
pass() { printf '  ok   %s\n' "$1"; }
bad()  { printf '  FAIL %s\n' "$1"; fail=1; }

echo "web server / PHP logging"
if grep -RIn --include='*.conf' -E '^\s*access_log\s+(/|syslog|off)' "$ROOT/deploy" | grep -v 'access_log off' | grep -q .; then
  bad "an access_log directive writes somewhere"; else pass "no nginx access_log writes (all 'access_log off')"; fi
grep -q 'access_log off' "$ROOT/deploy/nginx/locksend.conf" && pass "production server block has access_log off" || bad "locksend.conf lacks access_log off"
grep -q 'access_log off' "$ROOT/deploy/relay/nginx-stream.conf" && pass "relay has access_log off" || bad "relay lacks access_log off"
grep -Eq '^access\.log\s*=\s*/dev/null' "$ROOT/deploy/php-fpm/locksend.conf" && pass "php-fpm access.log is /dev/null" || bad "php-fpm access log not disabled"
grep -Eq '^server_tokens off|server_tokens off;' "$ROOT/deploy/nginx/locksend.conf" && pass "server_tokens off" || bad "server_tokens not off"

echo "application never records addresses"
if grep -RIn -E 'REMOTE_ADDR|HTTP_X_FORWARDED|X-Real-IP|x-real-ip' "$ROOT/server/src" | grep -vE 'Request\.php' | grep -q .; then
  bad "address headers referenced outside Request.php"; else pass "address access is confined to Support/Request.php"; fi
if grep -RIn -E "error_log\(.*(clientAddress|REMOTE_ADDR)|file_put_contents\(.*(ip|addr)" "$ROOT/server/src" | grep -q .; then
  bad "possible address logging"; else pass "no address logging calls"; fi
grep -RIn 'clientAddress' "$ROOT/server/src" | grep -vE 'Request\.php|Limiter\.php' | grep -q . && bad "clientAddress used outside the limiter" || pass "clientAddress only feeds the in-memory limiter"

echo "no third-party resources"
if grep -RIn -E 'https?://' "$ROOT/web/src" "$ROOT/server/public" "$ROOT/server/templates" \
     --include='*.js' --include='*.html' --include='*.css' --exclude='zxcvbn.*.js' --exclude='*.min.js' --exclude='build-manifest.json' \
   | grep -vE 'http://(127\.0\.0\.1|localhost)|//www\.w3\.org/|assets/' | grep -vE 'https?://[^"]*\.(wikipedia|eff)\.' \
   | grep -vE '^\S+:[0-9]+:\s*(//|\*|/\*)' | grep -q .; then
  grep -RIn -E 'https?://' "$ROOT/web/src" "$ROOT/server/public" "$ROOT/server/templates" --include='*.js' --include='*.html' --include='*.css' --exclude='zxcvbn.*.js' \
    | grep -vE 'http://(127\.0\.0\.1|localhost)|//www\.w3\.org/' | grep -vE '^\S+:[0-9]+:\s*(//|\*|/\*)' | head -5
  bad "absolute http(s) URLs found in sources/pages (listed above)"
else pass "no absolute external URLs in sources or generated pages"; fi
if grep -RIn -E '<(script|link|img|iframe)[^>]+(src|href)="https?:' "$ROOT/server/public" "$ROOT/server/templates" --include='*.html' | grep -q .; then
  bad "external resource tag in HTML"; else pass "HTML pulls nothing from other origins"; fi
grep -q "default-src 'none'" "$ROOT/deploy/nginx/locksend-headers.conf" && pass "CSP default-src 'none'" || bad "CSP missing"
if grep -hE "unsafe-inline|'unsafe-eval'" "$ROOT/deploy/nginx/locksend-headers.conf" "$ROOT/deploy/nginx/locksend.conf" "$ROOT/server/public/index.php" | grep -q .; then
  bad "CSP allows unsafe-inline / unsafe-eval"; else pass "CSP has no unsafe-inline / unsafe-eval (only wasm-unsafe-eval)"; fi
if grep -RIn -E "(localStorage|sessionStorage|indexedDB|document\.cookie)" "$ROOT/web/src" | grep -q .; then
  bad "browser storage used"; else pass "no localStorage/sessionStorage/IndexedDB/cookie access in front-end source"; fi

echo "secrets in repository"
if grep -RIn -E 'LOCKSEND_PEPPER\s*=\s*[A-Za-z0-9+/]{20,}' "$ROOT" --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.git | grep -q .; then
  bad "pepper value committed"; else pass "no pepper value in the tree"; fi
[ -z "$(find "$ROOT/server/data" -type f ! -name '.gitkeep' 2>/dev/null)" ] && pass "server/data holds no runtime files in the tree" || bad "runtime files present in server/data"

if [ -n "$DATA_DIR" ]; then
  echo "data directory ($DATA_DIR)"
  find "$DATA_DIR" -type f -perm /007 2>/dev/null | grep -q . && bad "world-accessible files under data dir" || pass "no world-accessible files"
  [ -f "$DATA_DIR/locksend.sqlite" ] && command -v sqlite3 >/dev/null && \
    sqlite3 "$DATA_DIR/locksend.sqlite" "select count(*) from shares where status in ('burned','destroyed','expired') and (enc_meta!='' or sealed_name!='' or auth_verifier!='')" | grep -qx 0 \
    && pass "closed shares carry no metadata" || true
fi

echo
[ "$fail" = 0 ] && echo "audit: all checks passed" || echo "audit: FAILURES"
exit $fail
