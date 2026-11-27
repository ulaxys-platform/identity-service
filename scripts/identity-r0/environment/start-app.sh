#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "$0")/runtime.sh"
create_env identity
run_once identity-cloud-db --env-file "$state/identity.env" "$pg"
wait_pg identity-cloud-db identity
if [ ! -f "$state/app.env" ]; then
    printf 'APP_ENV=local\nAPP_DEBUG=false\nAPP_KEY=base64:%s\nSERVICE_SIGNATURE=%s\nLOG_CHANNEL=stderr\nDB_CONNECTION=pgsql\nDB_HOST=identity-cloud-db\nDB_DATABASE=identity\nDB_USERNAME=identity\nDB_PASSWORD=%s\nDB_SSLMODE=disable\n' \
        "$(openssl rand -base64 32)" "$(openssl rand -hex 32)" "$(sed -n 's/^POSTGRES_PASSWORD=//p' "$state/identity.env")" > "$state/app.env"
fi
run_once identity-cloud-app --env-file "$state/app.env" -v "$PWD:/app" -v "$vendor:/app/vendor" -w /app "$dev" php artisan serve --host=0.0.0.0 --port=8000
for _ in {1..30}; do
    if docker exec "$stack-app" curl -fsS http://127.0.0.1:8000/healthCheck/apiStatus &&
       docker exec "$stack-app" curl -fsS http://127.0.0.1:8000/healthCheck/postgresStatus; then
        printf '\n'
        docker exec "$stack-app" php artisan migrate --force --no-interaction
        exit 0
    fi
    sleep 1
done
exit 1
