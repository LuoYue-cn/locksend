#!/usr/bin/env bash
# Builds the upload-ready server package in dist/locksend-server/ containing only runtime files:
#   bin/ migrations/ public/ src/ templates/ data/ (empty) composer.json composer.lock vendor/ (no dev deps)
# Tests, phpunit/phpcs config and the Dockerfile are left out.
# Needs php + composer on PATH (or COMPOSER_BIN="php /path/to/composer.phar"). Run `npm run build` in web/ first if the front end changed.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/locksend-server"
read -r -a COMPOSER_CMD <<< "${COMPOSER_BIN:-composer}"
unset COMPOSER   # composer reads $COMPOSER as the path to composer.json

[ -f "$ROOT/server/public/index.html" ] || { echo "front end not built: run 'npm ci && npm run build' in web/"; exit 1; }

rm -rf "$OUT"
mkdir -p "$OUT/data"
for d in bin migrations public src templates; do cp -r "$ROOT/server/$d" "$OUT/$d"; done
cp "$ROOT/server/composer.json" "$ROOT/server/composer.lock" "$OUT/"
touch "$OUT/data/.gitkeep"

LOG="$(mktemp)"
if ! ( cd "$OUT" && "${COMPOSER_CMD[@]}" install --no-dev --optimize-autoloader --no-interaction --no-progress ) > "$LOG" 2>&1; then
  cat "$LOG"; rm -f "$LOG"; echo "composer install failed"; exit 1
fi
rm -f "$LOG"
[ -f "$OUT/vendor/autoload.php" ] || { echo "vendor/autoload.php missing"; exit 1; }
if [ -d "$OUT/vendor/phpunit" ] || [ -d "$OUT/vendor/squizlabs" ]; then echo "dev dependencies leaked into the package"; exit 1; fi

echo "package ready: $OUT"
