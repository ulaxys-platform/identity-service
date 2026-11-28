# Operator runbook

## Initial bring-up

1. Configure `.env` from `.env.example` (PostgreSQL, `SERVICE_SIGNATURE`, `APP_KEY`, `APP_URL`, Identity V2 variables).
2. `php artisan migrate --force`
3. `php artisan identity:keys:generate` (first key). Rotation: `php artisan identity:keys:generate --rotate` during a maintenance window; JWKS overlap keeps old tokens valid for `identity.keys.retired_publication_days`.
4. `php artisan identity:bootstrap-admin ops@example.com --display-name "Platform Operator" --output /secure/bootstrap-token.txt` — prints/writes the single-use setup token ONCE; the operator account activates via `POST /api/v1/auth/setup` with that token. Re-running the command is idempotent and changes nothing. The command refuses to escalate an existing user without a grant.

## Daily operations

- Health: `GET /healthCheck/apiStatus` (liveness), `GET /healthCheck/postgresStatus` (readiness).
- Token rotation incident: rotate keys with overlap removal (`IDENTITY_RETIRED_KEY_DAYS=0` deployment setting or immediate re-rotation) — all tokens signed by retired keys invalidate; users re-login.
- Suspected session compromise: revoke via `POST /api/v1/oauth/revoke` with any access token of the session, or revoke all sessions by password reset flow. Every action is audited.
- Cache outage: security endpoints fail closed (401/503/429 paths degrade to 5xx); do not disable rate limiting to "restore service".

## One-time token delivery

`IDENTITY_DELIVERY_DRIVER=null` (default) delivers nothing — audit-only. `log` writes plaintext one-time tokens to `IDENTITY_DELIVERY_LOG_PATH` for local development only; never enable it in production. Production SMTP/notification integration is an I2 deliverable through `TokenDeliveryInterface`.

## Diagnostics

`php artisan route:list` shows the module set. `composer openapi` regenerates and gates the OpenAPI artifact. `php vendor/bin/phpunit --testsuite Foundation` runs without a database; the Database suite requires the disposable-PostgreSQL opt-in (`service_test`/`service_testing` + `SERVICE_ALLOW_DESTRUCTIVE_TESTS=true`) enforced by the template guard.
