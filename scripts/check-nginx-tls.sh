#!/usr/bin/env bash
# Validates the PRODUCTION nginx files in deploy/nginx with a throw-away self-signed certificate:
#   1. `nginx -t` accepts them
#   2. a live handshake negotiates TLS 1.3 and TLS 1.2 / 1.1 / 1.0 are refused
#   3. security headers are present and source/data paths are 404
# Only filesystem paths and listen ports are rewritten into a scratch dir; directives are untouched.
# Needs nginx, openssl, python3, curl on PATH.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
R="${R:-/tmp/ls-tls}"
HTTPS_PORT="${HTTPS_PORT:-8443}"
HTTP_PORT="${HTTP_PORT:-8081}"
fail=0
ok()  { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1"; fail=1; }

cleanup() { [ -f "$R/nginx.pid" ] && kill "$(cat "$R/nginx.pid")" 2>/dev/null; sleep 0.3; rm -rf "$R"; }
trap cleanup EXIT

rm -rf "$R"; mkdir -p "$R"/{conf,ssl,tmp,blobs,data}
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 1 \
  -subj "/CN=example.invalid" -keyout "$R/ssl/privkey.pem" -out "$R/ssl/fullchain.pem" 2>/dev/null

for f in locksend.conf locksend-locations.conf locksend-headers.conf locksend-fastcgi.conf; do cp "$REPO/deploy/nginx/$f" "$R/conf/$f"; done
sed -i -e "s#/etc/ssl/locksend/#$R/ssl/#g" -e "s#/etc/nginx/locksend-#$R/conf/locksend-#g" \
  -e "s#/var/log/nginx/locksend-error.log#$R/error.log#" -e "s#root /var/www/locksend/server/public;#root $REPO/server/public;#" \
  -e "s#listen 80;#listen 127.0.0.1:$HTTP_PORT;#; /listen \[::\]:80;/d" \
  -e "s#listen 443 ssl;#listen 127.0.0.1:$HTTPS_PORT ssl;#; /listen \[::\]:443 ssl;/d" \
  -e "s#unix:/run/php/locksend.sock#127.0.0.1:1#" "$R/conf/locksend.conf"
sed -i -e "s#/etc/nginx/locksend-#$R/conf/locksend-#g" -e "s#/var/lib/locksend/blobs/#$R/blobs/#" "$R/conf/locksend-locations.conf"
sed -i -e "s#include fastcgi_params;#include $R/conf/fastcgi_params;#" "$R/conf/locksend-fastcgi.conf"
PFX="$(dirname "$(dirname "$(command -v nginx)")")"
cp "$PFX/etc/nginx/fastcgi_params" "$R/conf/fastcgi_params" 2>/dev/null || cp "$PFX/conf/fastcgi_params" "$R/conf/fastcgi_params"
cat > "$R/conf/nginx.conf" <<EOF
worker_processes 1; pid $R/nginx.pid; error_log $R/error.log warn;
events { worker_connections 64; }
http {
  include $PFX/etc/nginx/mime.types;
  client_body_temp_path $R/tmp/b; proxy_temp_path $R/tmp/p; fastcgi_temp_path $R/tmp/f; uwsgi_temp_path $R/tmp/u; scgi_temp_path $R/tmp/s;
  include $R/conf/locksend.conf;
}
EOF

echo "nginx -t"
if nginx -t -c "$R/conf/nginx.conf" -p "$R" 2>&1 | grep -q "test is successful"; then ok "production configuration parses and tests successfully"; else bad "nginx -t failed"; nginx -t -c "$R/conf/nginx.conf" -p "$R"; fi
grep -q 'ssl_protocols TLSv1.3;' "$REPO/deploy/nginx/locksend.conf" && ok "ssl_protocols is exactly TLSv1.3" || bad "ssl_protocols is not TLSv1.3 only"

nginx -c "$R/conf/nginx.conf" -p "$R" 2>/dev/null; sleep 0.8

echo "live handshakes"
python3 - "$HTTPS_PORT" <<'EOF' || fail=1
import ssl, socket, sys
port = int(sys.argv[1]); rc = 0
def attempt(lo, hi):
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    ctx.minimum_version = lo; ctx.maximum_version = hi
    with socket.create_connection(("127.0.0.1", port), 5) as s, ctx.wrap_socket(s) as t:
        return t.version()
V = ssl.TLSVersion
try:
    v = attempt(V.TLSv1_3, V.TLSv1_3); print("  ok   TLS 1.3 negotiated" if v == "TLSv1.3" else f"  FAIL got {v}"); rc |= v != "TLSv1.3"
except Exception as e:
    print("  FAIL TLS 1.3 handshake failed:", e); rc = 1
for name, lo, hi in [("TLS 1.2", V.TLSv1_2, V.TLSv1_2)]:
    try:
        v = attempt(lo, hi); print(f"  FAIL {name} was accepted ({v})"); rc = 1
    except ssl.SSLError as e:
        print(f"  ok   {name} refused ({str(e).split(']')[-1].strip()[:40]})")
    except Exception as e:
        print(f"  ok   {name} refused ({type(e).__name__})")
sys.exit(rc)
EOF

echo "responses over TLS 1.3"
H="$(curl -sk --tlsv1.3 -D - -o /dev/null "https://127.0.0.1:$HTTPS_PORT/")"
for h in strict-transport-security content-security-policy x-content-type-options referrer-policy cross-origin-opener-policy; do
  echo "$H" | grep -qi "^$h:" && ok "header $h" || bad "missing header $h"
done
echo "$H" | grep -qi "^content-security-policy:.*default-src 'none'" && ok "CSP default-src 'none'" || bad "CSP not strict"
echo "$H" | grep -qi '^server: nginx/' && bad "nginx version exposed" || ok "server_tokens off (no version in Server header)"
[ "$(curl -sk --tls-max 1.2 -o /dev/null -w '%{http_code}' "https://127.0.0.1:$HTTPS_PORT/")" = "000" ] && ok "curl limited to TLS 1.2 gets no response" || bad "TLS 1.2 curl got a response"
loc="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://127.0.0.1:$HTTP_PORT/x")"
case "$loc" in "301 https://"*) ok "plain HTTP redirects to HTTPS ($loc)";; *) bad "no HTTP->HTTPS redirect ($loc)";; esac
for p in /server.php /composer.json /vendor/autoload.php /data/locksend.sqlite /.git/config /_blobs/x /src/Api/App.php; do
  [ "$(curl -sk --tlsv1.3 -o /dev/null -w '%{http_code}' "https://127.0.0.1:$HTTPS_PORT$p")" = "404" ] && ok "$p -> 404" || bad "$p is reachable"
done

echo
[ "$fail" = 0 ] && echo "nginx/TLS check: all passed" || echo "nginx/TLS check: FAILURES"
exit $fail
