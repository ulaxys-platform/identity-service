# Acceptance matrix (I1 review unit)

Classification legend: IMPLEMENTED (code exists) / TESTED (shipped suites cover it) / VERIFIED (ran in the target environment) / NOT VERIFIED (could not run here) / DEFERRED (planned unit) / BLOCKED (environment).

| Capability | Status | Evidence |
| --- | --- | --- |
| Repository provenance & conventions | VERIFIED | Inspection of actual checkout at `072a5ad8`; `.ulaxys/generation.json` matches template commit `5abad787` |
| Symfony advisory disposition | VERIFIED (analysis) | Lockfile inspected (`http-kernel` 8.1.11, `mailer` 8.1.10, `routing` 8.1.11 < 8.1.12); ecosystem release notes found for Nov 25, 2026 |
| composer.lock refresh to >= 8.1.12 | BLOCKED | No PHP/Composer runtime in sandbox; exact commands in docs/dependency-security.md |
| Template defect fixes (CI, parity, fixture skip) | IMPLEMENTED | `.github/workflows/ci.yml` (reconstructed), `HsCodeReferenceParityTest` adjustments, docs/template-defects.md |
| ADRs 0001-0005 | IMPLEMENTED | docs/adr/ |
| Identity schema (12 migrations) | IMPLEMENTED + TESTED | Database suite (guarded disposable PostgreSQL) |
| Login/logout/refresh/rotation/reuse detection | IMPLEMENTED + TESTED | IdentityAuthenticationFlowTest, IdentityTokenLifecycleTest |
| Enumeration resistance & throttling | IMPLEMENTED + TESTED | identical-denial, throttling, forgot-response equality tests |
| Password policy & change/reset flows | IMPLEMENTED + TESTED | IdentityCredentialLifecycleTest |
| Bootstrap admin (idempotent, auditable, no seeded passwords) | IMPLEMENTED + TESTED | IdentityCredentialLifecycleTest, IdentitySecurityRegressionTest |
| Sessions (list/revoke current/all/one, IDOR safety) | IMPLEMENTED + TESTED | IdentitySecurityRegressionTest, IdentityAuthenticationFlowTest |
| JWKS / introspection / revoke contract | IMPLEMENTED + TESTED | IdentityTokenLifecycleTest |
| Key rotation with overlap | IMPLEMENTED + TESTED | IdentityTokenLifecycleTest |
| Actor-context contract | IMPLEMENTED + TESTED | IdentitySecurityRegressionTest |
| en/tr/ar localization | IMPLEMENTED + TESTED | LocalizationContractTest |
| Integration metadata for every mounted route | IMPLEMENTED + TESTED | IntegrationMetadataTest |
| JWT codec security (alg allowlist, iss/aud/exp/nbf/kid) | IMPLEMENTED + TESTED | JwtCodecTest |
| Foundation suite run (FoundationTest et al.) | NOT VERIFIED | Requires PHP runtime; commands documented below |
| Database suite run on disposable PostgreSQL | NOT VERIFIED | Requires PHP + PostgreSQL runtime |
| OpenAPI generation + release gate | NOT VERIFIED | Requires PHP runtime |
| Pint / Prettier clean pass | NOT VERIFIED | Requires PHP/Node toolchain |
| Production Docker build + smoke | NOT VERIFIED | Requires Docker |
| E2E acceptance scenarios A-G | PARTIALLY (F covered by contract tests) / DEFERRED (B-E need I3/I4) | docs/benchmarking.md, docs/roadmap.md |
| Real Google/Microsoft OIDC login | DEFERRED (I2) + NOT VERIFIED | docs/identity-providers.md |

## Reproduction commands (Linux, PHP 8.5 + Composer 2)

```sh
composer validate --strict
composer install --prefer-dist
composer audit
composer check-platform-reqs
find app bootstrap config routes database tests public -name '*.php' -exec php -l {} \;
php vendor/bin/pint --test
php vendor/bin/phpunit --testsuite Foundation
# disposable PostgreSQL named service_test, APP_ENV=testing, SERVICE_ALLOW_DESTRUCTIVE_TESTS=true
php vendor/bin/phpunit --testsuite Database
composer openapi
php artisan ulaxys:generate-integration --all --strict --dry-run
docker build --target production -t identity-service:ci .
python3 tests/Runtime/smoke-container.py identity-service:ci runtime.env ci-network
```
