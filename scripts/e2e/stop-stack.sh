#!/usr/bin/env bash
# Stops the throwaway stack and deletes its run directory.
RUN="${RUN:-/tmp/ls-run}"
for p in nginx.pid redis.pid php.pid; do
  if [ -f "$RUN/$p" ]; then
    pid="$(cat "$RUN/$p")"
    kill "$pid" 2>/dev/null || true
  fi
done
pkill -f "php-cgi -b 127.0.0.1:${FCGI_PORT:-9099}" 2>/dev/null || true
pkill -f "php-fpm: master process (${RUN}/fpm.conf)" 2>/dev/null || true
sleep 0.5
rm -rf "$RUN"
echo "stack stopped, $RUN removed"
