# External identity providers

Status: DEFERRED to I2. Real Google/Microsoft acceptance will be NOT VERIFIED until provider credentials and live acceptance exist; automated tests will use a controlled mock OIDC server.

## Pre-drawn design (must be preserved when implementing)

- Provider registry with trusted issuer allowlist, client IDs, secret *references* (never secrets in DB), discovery metadata caching, approved authorization/token endpoints and exact redirect-URI matching.
- State + nonce + PKCE on every browser flow; ID-token validation against the provider's JWKS with strict issuer/audience; replayed authentication responses rejected via nonce/state single-use storage.
- Uniqueness of external identities keyed by (verified provider issuer, provider subject) — never by email. Two providers returning the same email never auto-link accounts; linking requires an authenticated, explicit flow.
- Provider access tokens are never treated as uLaxys access tokens.
- Attack coverage required by the task brief: account takeover, login CSRF, authorization-code substitution, open redirects, provider mix-up, discovery SSRF (endpoints allowlisted), invalid issuer/audience acceptance.

## Keycloak provider staging

R1C adopted [PHP Service Template P4.5 PR #22](https://github.com/ulaxys-platform/ulaxys-be-php-template-service/pull/22) at `ca35c3ad90b81786a732f4dbc1d44ab4f12ee3cd`. On the Identity branch the controlled upgrade added the schema-v2 security contract, conjunctive OpenAPI authentication, dependency probes and shared CLI acceptance. The original generation provenance still identifies the original template snapshot.

`php artisan identity:provider-stage <request-ulid>` is a trusted operator command for staging an invited platform user. Reuse the same request ULID after a failure. The command returns the platform ULID. It creates a disabled account through the Keycloak Admin API and never requests or stores a password. The installed realm must reserve the `identity-` username prefix and expose the single-valued `identity_platform_ulid` user-profile attribute to the administration service account only. Use a dedicated service account with `kc-admin-api` and the realm user permissions required for creation and lookup. Keep credentials in the runtime secret store.

Set `KEYCLOAK_BASE_URL`, `KEYCLOAK_REALM`, `KEYCLOAK_ISSUER`, `KEYCLOAK_ADMIN_CLIENT_ID` and `KEYCLOAK_ADMIN_CLIENT_SECRET`. The issuer must exactly equal the HTTPS base followed by `/realms/<realm>`. Use a certificate trusted by PHP. Redirects are disabled. Optional `KEYCLOAK_CONNECT_TIMEOUT` and `KEYCLOAK_REQUEST_TIMEOUT` default to two and five seconds.

The PostgreSQL migration adds `identity_provider_mappings`. The request primary key and row lock serialize local creation, while the unique provider issuer and subject prevent duplicate bindings. The provider call runs outside the local transaction. The deterministic provider username and service-controlled ULID attribute allow recovery after a lost response with no account association by email. An uncertain operation is marked `retry_required`. A successful mapping and its audit event are committed together. Both platform and provider accounts remain ineligible for sign-in through this flow.

This increment covers provisioning staging and retry. Keycloak-powered actor validation, session and token administration, production activation and bootstrap, reference data, and removal of the original authentication engine remain open. The current HTTP routes still use their prior authority. The R0 scripts exercise a separate disposable Keycloak realm over its development network. They do not exercise this HTTPS-only staging adapter. The new PostgreSQL regression uses HTTP fault injection to verify recovery after provider creation with a lost response.

IDV2-005 and IDV2-014 have partial provisioning safeguards and remain open for bootstrap and lifecycle transitions. IDV2-015 has a transactional staging audit assertion and remains open for the complete lifecycle. The other dispositions in [the security review](review-remediation.md) are unaffected.

### Cloud verification for the staging increment

Starting Identity revision `a21c2932a941401347de9672a51573780f9a242f`. Foundation completed with 174 tests and 970 assertions after fixing local output ownership and finishing Composer installation before rerunning. The disposable PostgreSQL suite completed with 58 tests and 253 assertions. Tooling completed with 14 tests. There were no failures or skips in these final suite runs. OpenAPI verified 24 operations. The command `php artisan ulaxys:generate-integration --all --gateway-format=json --strict --dry-run --output=r23-review` completed successfully with schema-v2 review output. The real Keycloak R0 check completed successfully.

The existing CA-aware installer completed successfully. The standard CLI install, smoke and runtime encountered Debian TLS trust errors during image builds. Standard verify and full acceptance stopped during Composer audit with a proxy CA error. The direct CA-aware `composer audit --locked` reached the proxy and returned CONNECT 403. Audit and container acceptance remain open.

On Windows Docker Desktop, use the repository wrappers from the checkout root:

```powershell
.\tools install
.\tools test tooling
.\tools test foundation
.\tools test database
.\tools verify
.\tools smoke
.\tools runtime
.\tools acceptance full
```

The staging increment leaves R2 and R3 incomplete. Existing provider-independent evidence is not release acceptance.
