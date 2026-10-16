#!/usr/bin/env bash
# Throwaway stack that mirrors production wiring: nginx -> PHP-FPM (unix socket, pool file from
# deploy/php-fpm/locksend.conf) -> memory-only Redis over a unix socket (ext-redis).
# Only paths, the user/group and the pepper are rewritten; every other pool/nginx directive is the shipped one.
# Needs php-fpm, nginx, redis-server on PATH. Removed by stop-stack.sh.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RUN="${RUN:-/tmp/ls-run}"
PORT="${PORT:-8088}"
ME="$(id -un)"
MYGRP="$(id -gn)"

rm -rf "$RUN"
mkdir -p "$RUN"/{data,nginx/tmp,logs}

# ---- php-fpm: the shipped pool file with paths rewritten -------------------------------------------------
sed -e "s#^user = .*#user = $ME#; s#^group = .*#group = $MYGRP#" \
    -e "s#^listen = .*#listen = $RUN/php.sock#" \
    -e "s#^listen.owner = .*#listen.owner = $ME#; s#^listen.group = .*#listen.group = $MYGRP#" \
    -e "s#/var/lib/locksend#$RUN/data#g" \
    -e "s#unix:///run/redis/locksend.sock#unix://$RUN/redis.sock#" \
    -e "s#/var/log/php-locksend-error.log#$RUN/logs/php-error.log#" \
    -e "s#/var/www/locksend/server#$ROOT/server#g" \
    -e "s#^; env\[LOCKSEND_PEPPER\] = .*#env[LOCKSEND_PEPPER] = \"base64:$(head -c 32 /dev/urandom | base64)\"#" \
    "$ROOT/deploy/php-fpm/locksend.conf" > "$RUN/pool.conf"
grep -q '^env\[LOCKSEND_PEPPER\]' "$RUN/pool.conf" || { echo "pepper line not enabled"; exit 1; }
cat > "$RUN/fpm.conf" <<EOF
[global]
pid = $RUN/php.pid
error_log = $RUN/logs/fpm.log
daemonize = yes
include = $RUN/pool.conf
EOF

# ---- nginx: shipped location/header/fastcgi files ------------------------------------------------------
cp "$ROOT"/deploy/nginx/locksend-{locations,headers,fastcgi}.conf "$RUN/nginx/"
sed -i -e "s#alias /var/lib/locksend/blobs/;#alias $RUN/data/blobs/;#" \
       -e "s#/etc/nginx/locksend-#$RUN/nginx/locksend-#g" "$RUN/nginx/locksend-locations.conf"
sed -i -e "s#/var/lib/locksend#$RUN/data#" -e "s#unix:///run/redis/locksend.sock#unix://$RUN/redis.sock#" \
       -e "s#include fastcgi_params;#include $RUN/nginx/fastcgi_params;#" "$RUN/nginx/locksend-fastcgi.conf"
sed -i '/Strict-Transport-Security/d' "$RUN/nginx/locksend-headers.conf"   # plain HTTP locally
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
    upstream ls_php { server unix:$RUN/php.sock; }
    server {
        listen 127.0.0.1:$PORT;
        root $ROOT/server/public;
        index index.html;
        client_max_body_size 5m;
        include $RUN/nginx/locksend-locations.conf;
    }
}
EOF

# ---- redis: shipped settings, unix socket only -----------------------------------------------------------
sed -e "s#/run/redis/locksend.sock#$RUN/redis.sock#" -e "s#^bind .*#bind 127.0.0.1#" \
    -e "s#^unixsocketperm .*#unixsocketperm 700#" "$ROOT/deploy/redis/redis.conf" > "$RUN/redis.conf"
cat >> "$RUN/redis.conf" <<EOF
daemonize yes
dir $RUN
pidfile $RUN/redis.pid
logfile $RUN/logs/redis.log
EOF
redis-server "$RUN/redis.conf" >/dev/null

php-fpm -y "$RUN/fpm.conf" -c "$(dirname "$(dirname "$(command -v php-fpm)")")/lib/php.ini"
nginx -c "$RUN/nginx/nginx.conf" -p "$RUN"
sleep 0.6
echo "fpm stack up: http://127.0.0.1:$PORT (run dir $RUN)"
