# Production runtime and deployment

## Preserved template infrastructure

The generated Docker runtime (Nginx + PHP-FPM + Supervisor, non-root, stderr logging, runtime refusal of missing `APP_KEY`/`SERVICE_SIGNATURE`/database identity, health probes `GET /healthCheck/apiStatus` and `/healthCheck/postgresStatus`, `GET /openapi.json` behind the Signature header) is preserved unchanged. The production image refuses debug mode. Redis remains optional; I1 works with file/array cache (rate limiting included).

## Identity-specific deployment steps

1. Provision independent PostgreSQL credentials; never reuse the legacy Identity database.
2. `php artisan migrate --force` (never `migrate:fresh` outside disposable test databases).
3. `php artisan identity:keys:generate` — or mount PEM files from the secret manager and insert matching `identity_signing_keys` metadata; private keys must be mode 0600, never committed, never served.
4. Set `IDENTITY_ISSUER` and `IDENTITY_TOKEN_AUDIENCE`; publish both to the Gateway.
5. `php artisan identity:bootstrap-admin <identifier> --output=/secure/path` — auditable, idempotent, no seeded passwords; the setup token is stored nowhere (ADR-0004).
6. Database role hardening: application role without UPDATE/DELETE on `identity_audit_events`/`identity_security_events` (append-only enforcement below the application layer); retention purge runs under a separate role (job DEFERRED to I5).
7. Rate-limit cache: file cache works single-instance; multi-instance deployments should select `CACHE_DRIVER=redis` with the service-unique `REDIS_PREFIX`. During a cache outage security-sensitive endpoints fail closed (500/503), never open.

## Handoff alias

Suggested deployment alias `identity-v2`; the final alias is decided during platform migration. The legacy Identity service is not replaced by this unit. Automated deployment does not happen from this branch.
