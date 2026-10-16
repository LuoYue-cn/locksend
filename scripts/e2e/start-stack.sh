#!/usr/bin/env bash
# Starts a throwaway local stack (nginx + php-cgi + redis) for end-to-end tests.
# Everything lives under $RUN (default /tmp/ls-run) and is removed by stop-stack.sh.
# Requires php, nginx and redis-server on PATH (e.g. PATH=/tmp/lsenv/bin:$PATH).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RUN="${RUN:-/tmp/ls-run}"
PORT="${PORT:-8088}"
FCGI_PORT="${FCGI_PORT:-9099}"
REDIS_PORT="${REDIS_PORT:-6399}"

rm -rf "$RUN"
mkdir -p "$RUN"/{data,nginx/tmp,logs}
cp "$ROOT"/deploy/nginx/locksend-{locations,headers}.conf "$RUN/nginx/"

# Local variants: plain HTTP on localhost, relative data paths, TCP redis, same routing rules.
sed -e "s#alias /var/lib/locksend/blobs/;#alias $RUN/data/blobs/;#" \
    -e "s#/etc/nginx/locksend-headers.conf#$RUN/nginx/locksend-headers.conf#g" \
    -e "s#/etc/nginx/locksend-fastcgi.conf#$RUN/nginx/locksend-fastcgi.conf#g" \
    "$ROOT/deploy/nginx/locksend-locations.conf" > "$RUN/nginx/locksend-locations.conf"
# HSTS is meaningless over plain HTTP.
sed -i '/Strict-Transport-Security/d' "$RUN/nginx/locksend-headers.conf"

cat > "$RUN/nginx/locksend-fastcgi.conf" <<EOF
fastcgi_pass 127.0.0.1:$FCGI_PORT;
include $RUN/nginx/fastcgi_params;
fastcgi_param LOCKSEND_DATA_DIR      $RUN/data;
fastcgi_param LOCKSEND_ACCEL_PREFIX  /_blobs;
fastcgi_param LOCKSEND_REDIS         tcp://127.0.0.1:$REDIS_PORT;
fastcgi_param LOCKSEND_TEMPLATES_DIR $ROOT/server/templates;
fastcgi_param HTTP_X_REAL_IP "";
fastcgi_param HTTP_X_FORWARDED_FOR "";
EOF

NGX_PREFIX="$(dirname "$(dirname "$(command -v nginx)")")"
cp "$NGX_PREFIX/etc/nginx/fastcgi_params" "$RUN/nginx/fastcgi_params" 2>/dev/null \
  || cp "$NGX_PREFIX/conf/fastcgi_params" "$RUN/nginx/fastcgi_params"

cat > "$RUN/nginx/nginx.conf" <<EOF
worker_processes 1;
pid $RUN/nginx.pid;
error_log $RUN/logs/nginx-error.log warn;
events { worker_connections 256; }
http {
    access_log off;
    client_body_temp_path $RUN/nginx/tmp/body;
    proxy_temp_path $RUN/nginx/tmp/proxy;
    fastcgi_temp_path $RUN/nginx/tmp/fcgi;
    uwsgi_temp_path $RUN/nginx/tmp/uwsgi;
    scgi_temp_path $RUN/nginx/tmp/scgi;
    include $NGX_PREFIX/etc/nginx/mime.types;
    default_type application/octet-stream;
    sendfile on;
    server_tokens off;
    limit_req_zone \$binary_remote_addr zone=ls_api:5m rate=200r/s;
    server {
        listen 127.0.0.1:$PORT;
        root $ROOT/server/public;
        index index.html;
        client_max_body_size 5m;
        include $RUN/nginx/locksend-locations.conf;
    }
}
EOF

redis-server --port "$REDIS_PORT" --bind 127.0.0.1 --save "" --appendonly no --daemonize yes \
  --dir "$RUN" --pidfile "$RUN/redis.pid" --logfile "$RUN/logs/redis.log" >/dev/null

( cd "$ROOT/server/public" && \
  PHP_FCGI_CHILDREN=4 PHP_FCGI_MAX_REQUESTS=0 \
  nohup php-cgi -b "127.0.0.1:$FCGI_PORT" -d display_errors=0 -d error_log="$RUN/logs/php-error.log" \
  >"$RUN/logs/php-cgi.out" 2>&1 & echo $! > "$RUN/php.pid" )

nginx -c "$RUN/nginx/nginx.conf" -p "$RUN"
sleep 0.5
echo "stack up: http://127.0.0.1:$PORT  (run dir $RUN)"
