#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
umask 077
fixture=$(mktemp -d)
realm="identity-r0-$(openssl rand -hex 6)"
stack=${IDENTITY_R0_STACK:-identity-cloud}
[[ "$stack" =~ ^identity-[a-z0-9-]+$ ]]
kc="$stack-keycloak"
app="$stack-app"
admin_config="/tmp/$realm-kcadm"
realm_file="/tmp/$realm.json"
cleanup() {
    docker exec "$kc" /opt/keycloak/bin/kcadm.sh delete "realms/$realm" --config "$admin_config" >/dev/null 2>&1 || true
    docker exec "$kc" rm -f "$admin_config" "$realm_file" >/dev/null 2>&1 || true
    rm -rf "$fixture"
}
trap cleanup EXIT
printf 'R0_REALM=%s\nR0_ADMIN_SECRET=%s\nR0_MACHINE_SECRET=%s\nR0_BROWSER_SECRET=%s\nR0_KEY_SECRET=%s\nR0_USER_PASSWORD=%s\n' \
    "$realm" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" > "$fixture/env"
docker exec "$kc" bash -ec '
    umask 077
    exec /opt/keycloak/bin/kcadm.sh config credentials --server http://localhost:8080 \
        --realm master --user "$KC_BOOTSTRAP_ADMIN_USERNAME" \
        --password "$KC_BOOTSTRAP_ADMIN_PASSWORD" --config "$1"
' _ "$admin_config"
python3 scripts/identity-r0/realm.py "$fixture/env" |
    docker exec -i "$kc" bash -ec 'umask 077; cat > "$1"' _ "$realm_file"
docker exec "$kc" /opt/keycloak/bin/kcadm.sh create realms -f "$realm_file" --config "$admin_config"
docker exec -i "$app" sh -ec 'umask 077; cat > "$1"' _ "/tmp/$realm.env" < "$fixture/env"
docker exec -i "$app" sh -ec '
    trap "rm -f \"\$1\"" EXIT
    set -a
    . "$1"
    set +a
    unset HTTP_PROXY HTTPS_PROXY ALL_PROXY http_proxy https_proxy all_proxy
    php
' sh "/tmp/$realm.env" < scripts/identity-r0/check.php
