#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../../.."
umask 077
stack=${IDENTITY_R0_STACK:-identity-cloud}
[[ "$stack" =~ ^identity-[a-z0-9-]+$ ]]
state=${IDENTITY_R0_STATE:-/workspace/$stack}
mkdir -p "$state/composer-cache"
key=$(printf '%s' "$PWD" | sha256sum | cut -c1-12)
dev="ulaxys-php-tools:$key"
ca=/etc/ssl/certs/ca-certificates.crt
hosts=()
if proxy_ip=$(getent ahostsv4 proxy | awk 'NR==1 {print $1}') && [[ -n "$proxy_ip" ]]; then
    hosts=(--add-host "proxy:$proxy_ip")
fi
docker build "${hosts[@]}" --secret "id=proxy_ca,src=$ca" \
    --build-arg HTTPS_PROXY --build-arg HTTP_PROXY --build-arg NO_PROXY \
    --target development -t "$dev" .
python3 scripts/identity-r0/environment/cache-composer.py "$state/composer-cache"
bash scripts/identity-r0/environment/composer.sh install --no-interaction --no-progress --prefer-dist --no-scripts
