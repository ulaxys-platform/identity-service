#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../../.."
stack=${IDENTITY_R0_STACK:-identity-cloud}
[[ "$stack" =~ ^identity-[a-z0-9-]+$ ]]
state=${IDENTITY_R0_STATE:-/workspace/$stack}
umask 077
mkdir -p "$state"
key=$(printf '%s' "$PWD" | sha256sum | cut -c1-12)
dev="ulaxys-php-tools:$key"
vendor="ulaxys-php-vendor-$key"
if [[ "$stack" != identity-cloud ]]; then
    vendor="$vendor-$stack"
fi
net="$stack-local"
pg=postgres:17@sha256:b8faeff7712663c2773a190181e1246e37e364b887611c04658c28b44e2408fe
create_env() {
    if [ ! -f "$state/$1.env" ]; then
        printf 'POSTGRES_DB=%s\nPOSTGRES_USER=%s\nPOSTGRES_PASSWORD=%s\n' "$1" "$1" "$(openssl rand -hex 32)" > "$state/$1.env"
    fi
}
run_once() {
    local alias=$1
    local name="$stack-${alias#identity-cloud-}"
    shift
    if ! docker container inspect "$name" >/dev/null 2>&1; then
        docker run -d --name "$name" --network "$net" --network-alias "$alias" "$@" >/dev/null
    elif [ "$(docker inspect -f '{{.State.Running}}' "$name")" != true ]; then
        docker start "$name" >/dev/null
    fi
}
wait_pg() {
    for _ in {1..60}; do
        if docker exec "$stack-${1#identity-cloud-}" pg_isready -h 127.0.0.1 -U "$2" -d "$2" >/dev/null 2>&1; then return; fi
        sleep 1
    done
    return 1
}
if ! docker network inspect "$net" >/dev/null 2>&1; then
    docker network create --internal "$net" >/dev/null ||
        docker network inspect "$net" >/dev/null
fi
