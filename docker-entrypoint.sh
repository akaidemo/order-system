#!/bin/sh
set -eu

mkdir -p "${DATA_DIR:-/data}/history"
touch "${DATA_DIR:-/data}/orders.txt"

if [ ! -f "${DATA_DIR:-/data}/users.json" ]; then
  printf '{ "管理員": 0 }\n' > "${DATA_DIR:-/data}/users.json"
fi

exec php -S "0.0.0.0:${PORT:-8080}" -t /app/public
