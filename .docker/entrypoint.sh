#!/bin/sh
set -eu
cd /app
umask 077
php /usr/local/bin/service-runtime-check.php
# Runtime only: inject environment secrets before caching; never cache at build.
# Do not run migrations or generate keys here.
rm -f bootstrap/cache/config.php
php artisan config:cache --no-interaction
if [ "${1:-}" != /usr/bin/supervisord ]; then
    exec "$@"
fi
# Preserve a failure exit code when Supervisor exhausts a required child's retries.
# PID 1 forwards shutdown signals; Supervisor gracefully drains both process groups.
rm -f /var/run/nginx/required-process-failed
"$@" &
supervisor_pid=$!
shutdown_requested=0
trap 'shutdown_requested=1; kill -TERM "$supervisor_pid" 2>/dev/null || true' TERM INT QUIT
set +e
wait "$supervisor_pid"
status=$?
if kill -0 "$supervisor_pid" 2>/dev/null; then
    wait "$supervisor_pid"
    status=$?
fi
if [ -f /var/run/nginx/required-process-failed ]; then
    exit 1
fi
# An interrupted wait may race with Supervisor's successful graceful exit.
if [ "$shutdown_requested" = 1 ]; then
    case "$status" in 130|131|143) status=0 ;; esac
fi
exit "$status"
