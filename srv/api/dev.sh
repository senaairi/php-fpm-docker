#!/bin/bash

cd -- "$(dirname -- "${BASH_SOURCE[0]}")" || exit 1

export TZ="${TZ:-Asia/Shanghai}"
export PFS_HTTP_HOST="${PFS_HTTP_HOST:-127.0.0.1}"
export PFS_HTTP_PORT="${PFS_HTTP_PORT:-14245}"

if [[ -z "${PFS_CONFIG_FILE:-}" && -z "${PFS_CONFIG:-}" ]]; then
    export PFS_CONFIG_FILE="data/config.json"
fi

exec php main.php
