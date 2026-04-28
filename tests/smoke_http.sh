#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PORT=18080
BASE_URL="http://127.0.0.1:${PORT}"
SERVER_LOG="$(mktemp)"

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi
  rm -f "${SERVER_LOG}"
}

trap cleanup EXIT

php -S "127.0.0.1:${PORT}" -t . >"${SERVER_LOG}" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 50); do
  if ! kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
    break
  fi
  if curl -sS "${BASE_URL}/" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done

if ! kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
  if grep -q "Failed to listen" "${SERVER_LOG}"; then
    echo "HTTP smoke tests skipped: local port binding is not permitted in this environment"
    exit 0
  fi
  echo "FAIL: php built-in server exited unexpectedly" >&2
  cat "${SERVER_LOG}" >&2 || true
  exit 1
fi

assert_status() {
  local path="$1"
  local expected="$2"
  local actual
  actual="$(curl -sS -o /tmp/xlog-smoke-body.$$ -w '%{http_code}' "${BASE_URL}${path}")"
  if [[ "${actual}" != "${expected}" ]]; then
    echo "FAIL: expected ${path} to return ${expected}, got ${actual}" >&2
    cat /tmp/xlog-smoke-body.$$ >&2 || true
    exit 1
  fi
}

assert_body_contains() {
  local path="$1"
  local expected="$2"
  local body
  body="$(curl -sS "${BASE_URL}${path}")"
  if [[ "${body}" != *"${expected}"* ]]; then
    echo "FAIL: expected ${path} body to contain: ${expected}" >&2
    exit 1
  fi
}

assert_status "/" "200"
assert_body_contains "/" "XLOG"

assert_status "/creat.php" "200"
assert_body_contains "/creat.php" "cf-turnstile"

assert_status "/creat-article.php" "200"
assert_body_contains "/creat-article.php" "EasyMDE"

assert_status "/recent.html" "200"
assert_body_contains "/recent.html" "XLOG"

assert_status "/site-samples/m4lite7b2q.html" "200"
assert_body_contains "/site-samples/m4lite7b2q.html" "XLOG"

assert_status "/generate.php" "405"
assert_body_contains "/generate.php" "Method Not Allowed"

assert_status "/generate-article.php" "405"
assert_body_contains "/generate-article.php" "Method Not Allowed"

rm -f /tmp/xlog-smoke-body.$$
echo "HTTP smoke tests passed"
