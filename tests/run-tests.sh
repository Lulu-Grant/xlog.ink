#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

php tests/php/test_core.php
python3 -m unittest tests.python.test_build_recent
bash tests/smoke_http.sh
