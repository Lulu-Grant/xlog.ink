#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${XLOG_DEPLOY_REMOTE:-root@5.189.149.76}"
DEST="${XLOG_DEPLOY_DEST:-/www/wwwroot/xlog.ink}"
KEY="${XLOG_DEPLOY_KEY:-}"
MODE="${1:-deploy}"

if [[ "${MODE}" != "deploy" && "${MODE}" != "--check" ]]; then
  echo "Usage: $0 [--check]" >&2
  exit 2
fi

SSH_ARGS=(-o StrictHostKeyChecking=accept-new)
if [[ -n "${KEY}" ]]; then
  if [[ ! -f "${KEY}" ]]; then
    echo "Deploy key not found: ${KEY}" >&2
    exit 2
  fi
  SSH_ARGS=(-i "${KEY}" "${SSH_ARGS[@]}")
fi

cd "${ROOT}"

echo "xlog.ink deploy target: ${REMOTE}:${DEST}"

REMOTE_CHECK="cd '${DEST}' \
  && PHP_BIN=/www/server/php/80/bin/php \
  && if [ ! -x \"\${PHP_BIN}\" ]; then PHP_BIN=php; fi \
  && \"\${PHP_BIN}\" -r 'if (PHP_VERSION_ID < 80000) { fwrite(STDERR, \"PHP 8.0+ required\\n\"); exit(1); }' \
  && \"\${PHP_BIN}\" -v | head -n 1"

ssh "${SSH_ARGS[@]}" "${REMOTE}" "${REMOTE_CHECK}"

if [[ "${MODE}" == "--check" ]]; then
  echo "Remote preflight passed; no files were changed."
  exit 0
fi

git ls-files -z \
  | perl -0ne 'print unless m{\A(?:data|site|site-assets)/}' \
  | rsync -rltz --from0 --files-from=- \
      -e "ssh ${SSH_ARGS[*]}" \
      ./ "${REMOTE}:${DEST}/"

ssh "${SSH_ARGS[@]}" "${REMOTE}" "cd '${DEST}' \
  && PHP_BIN=/www/server/php/80/bin/php \
  && if [ ! -x \"\${PHP_BIN}\" ]; then PHP_BIN=php; fi \
  && mkdir -p data site site-assets data/php-sessions data/previews \
  && chown -R www:www data site site-assets \
  && if [ -f recent.html ]; then chown www:www recent.html && chmod 664 recent.html; fi \
  && rm -f includes/ratelimit.php js/qrcode.min.js \
  && chmod 750 data data/php-sessions data/previews \
  && find data -maxdepth 1 -type f -name 'xlog.db*' -exec chmod 660 {} \\; \
  && \"\${PHP_BIN}\" -l index.php \
  && \"\${PHP_BIN}\" -l api/session.php \
  && \"\${PHP_BIN}\" -l includes/db.php \
  && \"\${PHP_BIN}\" scripts/build-sitemap.php"
