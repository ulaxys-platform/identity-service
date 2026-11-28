#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
umask 077
stack=${IDENTITY_R0_STACK:-identity-cloud}
[[ "$stack" =~ ^identity-[a-z0-9-]+$ ]]
state=${IDENTITY_R0_STATE:-/workspace/$stack}
mkdir -p "$state/composer-cache"
key=$(printf '%s' "$PWD" | sha256sum | cut -c1-12)
vendor="ulaxys-php-vendor-$key"
if [[ "$stack" != identity-cloud ]]; then
    vendor="$vendor-$stack"
fi
ca=/etc/ssl/certs/ca-certificates.crt
hosts=()
if proxy_ip=$(getent ahostsv4 proxy | awk 'NR==1 {print $1}') && [[ -n "$proxy_ip" ]]; then
    hosts=(--add-host "proxy:$proxy_ip")
fi
exec docker run --rm "${hosts[@]}" -e HTTPS_PROXY -e HTTP_PROXY -e NO_PROXY \
    -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CAFILE=/cloud-ca.pem \
    -e COMPOSER_CACHE_DIR=/composer-cache \
    -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/app \
    -v "$state/composer-cache:/composer-cache" -v "$ca:/cloud-ca.pem:ro" \
    -v "$PWD:/app" -v "$vendor:/app/vendor" -w /app \
    "ulaxys-php-tools:$key" composer "$@"
