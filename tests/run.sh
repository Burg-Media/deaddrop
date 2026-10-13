#!/usr/bin/env bash
# Draait de end-to-end-test tegen een verse testdatabase.
#   PHP=/pad/naar/php MYSQL="mysql -uroot -p..." bash tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/.."

PHP=${PHP:-php}
MYSQL=${MYSQL:-mysql}
PORT=${PORT:-8766}
export PHP
export APP_URL="http://127.0.0.1:${PORT}"
export DB_DATABASE=${DB_DATABASE:-deaddrop_test}
export APP_COOKIE_SECURE=false
export APP_DEVICE_TRUST_REQUIRED=false

$MYSQL -e "DROP DATABASE IF EXISTS \`${DB_DATABASE}\`; CREATE DATABASE \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$PHP tools/migrate.php > /dev/null
$PHP tests/seed.php

$PHP -S "127.0.0.1:${PORT}" -t public > /tmp/deaddrop-e2e-server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER' EXIT
sleep 1

node tests/e2e.mjs "$APP_URL"
