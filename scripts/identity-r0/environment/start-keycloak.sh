#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/runtime.sh"
kc=quay.io/keycloak/keycloak:26.8.2@sha256:7e66a96e4739239070aeaf72e4c5decf0f7f682cfc725b9f7ea2b336eaba38b3
create_env keycloak
run_once identity-cloud-keycloak-db --env-file "$state/keycloak.env" "$pg"
wait_pg identity-cloud-keycloak-db keycloak
if [ ! -f "$state/kc.env" ]; then
    printf 'KC_DB=postgres\nKC_DB_URL=jdbc:postgresql://identity-cloud-keycloak-db:5432/keycloak\nKC_DB_USERNAME=keycloak\nKC_DB_PASSWORD=%s\nKC_BOOTSTRAP_ADMIN_USERNAME=poc-admin\nKC_BOOTSTRAP_ADMIN_PASSWORD=%s\n' \
        "$(sed -n 's/^POSTGRES_PASSWORD=//p' "$state/keycloak.env")" "$(openssl rand -hex 32)" > "$state/kc.env"
fi
run_once identity-cloud-keycloak --env-file "$state/kc.env" "$kc" start-dev --hostname http://identity-cloud-keycloak:8080
for _ in {1..90}; do
    if docker exec "$stack-keycloak" bash -ec 'exec 3<>/dev/tcp/127.0.0.1/8080; printf "GET /realms/master/.well-known/openid-configuration HTTP/1.0\r\nHost: identity-cloud-keycloak:8080\r\n\r\n" >&3; read -r status <&3; [[ "$status" == *" 200 "* ]]' 2>/dev/null; then
        printf 'Keycloak discovery: HTTP 200\n'
        exit 0
    fi
    sleep 1
done
exit 1
