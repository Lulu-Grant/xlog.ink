#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REMOTE="${XLOG_DEPLOY_REMOTE:-root@103.73.66.117}"
DEST="${XLOG_DEPLOY_DEST:-/www/wwwroot/xlog.ink}"
KEY="${XLOG_DEPLOY_KEY:-/Users/apple/Documents/xlog/bt.glsnote.org_id_ed25519}"

SSH_ARGS=(-o StrictHostKeyChecking=no)
if [[ -n "${KEY}" && -f "${KEY}" ]]; then
  SSH_ARGS=(-i "${KEY}" "${SSH_ARGS[@]}")
fi

cd "${ROOT}"

git ls-files -z \
  | perl -0ne 'print unless m{\A(?:data|site|site-assets)/}' \
  | rsync -rltz --from0 --files-from=- \
      -e "ssh ${SSH_ARGS[*]}" \
      ./ "${REMOTE}:${DEST}/"

ssh "${SSH_ARGS[@]}" "${REMOTE}" "cd '${DEST}' \
  && mkdir -p data site site-assets data/php-sessions data/previews \
  && chown -R www:www data site site-assets \
  && chmod 750 data data/php-sessions data/previews \
  && find data -maxdepth 1 -type f -name 'xlog.db*' -exec chmod 660 {} \\; \
  && php -l index.php \
  && php -l api/session.php \
  && php -l includes/db.php"
