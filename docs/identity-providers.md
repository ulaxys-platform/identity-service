# External identity providers

Status: DEFERRED to I2. Real Google/Microsoft acceptance will be NOT VERIFIED until provider credentials and live acceptance exist; automated tests will use a controlled mock OIDC server.

## Pre-drawn design (must be preserved when implementing)

- Provider registry with trusted issuer allowlist, client IDs, secret _references_ (never secrets in DB), discovery metadata caching, approved authorization/token endpoints and exact redirect-URI matching.
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

## Keycloak-backed HTTP and lifecycle continuation

The HTTP boundary now uses Firebase PHP-JWT for RS256 signatures, issuer-pinned discovery and JWKS, and a separate confidential client for authoritative introspection on every authenticated request. Configure `KEYCLOAK_API_AUDIENCE`, `KEYCLOAK_INTROSPECTION_CLIENT_ID` and `KEYCLOAK_INTROSPECTION_CLIENT_SECRET`. The introspection client must be allowed to introspect tokens for the API audience, and its identity must differ from the Admin API client. JWKS entries are cached for 120 seconds. An unknown key can trigger one refresh per 15 seconds in a shared cache. This operation depends on shared cache configuration for a multi-instance deployment.

Actor context resolves issuer and subject through an active platform mapping and requires an eligible human user. It preserves the platform ULID and includes the provider issuer and subject. The session ID is now Keycloak's opaque session ID. Logout revokes the session at Keycloak. Unavailability returns HTTP 503. Missing, inactive, invalid and unmapped identities receive HTTP 401. Machine credentials do not establish a human session.

`POST /api/v1/admin/users` now requires a ULID in `Idempotency-Key` and an email identifier. It stages the profile and contact email, and retries reuse the original request. `POST /api/v1/admin/users/{userId}/lifecycle` supports invite, activate, suspend and disable behind the interim administrator grant. The lifecycle route stays unpublished in schema-v2 Gateway review metadata. Trusted operators can use `identity:provider-stage <request-ulid> --email=<contact>`, `identity:provider-account <operation> <user-ulid>`, and `identity:provider-reconcile --limit=25`. Bootstrap is an explicit operator-only lifecycle operation, after confirmation that Keycloak completed activation. Existing historical grants require a separate cutover decision before assigning a new initial administrator.

Invitation sends Keycloak required actions for email verification and password setup with a 900-second lifespan. Configure Keycloak SMTP before using it. Local sign-in remains denied until the provider confirms an enabled account with verified email, expected correlation, and no outstanding actions. Disablement and suspension first deny local authorization, then disable the remote account and revoke its sessions. Retry pending changes with the reconciliation command. Mapping conflicts require operator investigation. SMTP delivery, replay of action links and races between competing lifecycle operations still require acceptance evidence.

Use a dedicated realm and apply the [realm policy fragment](../scripts/identity-r0/provider-realm-policy.json) to the realm settings and managed user-profile attributes. Merge the attribute into the existing profile configuration through the Keycloak Admin API. The test realm uses admin-only read/write permissions for the correlation attribute, with user registration and username editing closed. Reserve `identity-` usernames for the provisioning workflow and restrict other Admin API clients. Federation and any alternate account-registration path need a separate policy check before use.

The local login, refresh, setup, credential, OAuth/JWKS and session-list routes were unmounted. Consumers should use Keycloak OIDC authorization code with PKCE and its built-in account actions, token and JWKS endpoints. Current-session logout stays at the Identity endpoint. Other session-management consumers need a migration decision. The local bootstrap and key-generation commands were removed. The original local implementation and historical migrations remain in the source tree for regression disposition. No legacy database migration was performed. Country and locale reference validation still needs the authoritative Reference Service contract.

### Continuation verification

The real-provider checks used Keycloak 26.8.2 pinned to the R0 digest, dedicated PostgreSQL for Keycloak, and the separate Identity PostgreSQL database. The application trusted the fixture HTTPS certificate with hostname validation. Separate provider administration and introspection clients were used. The production adapter passed real staging, concurrent staging of the same request, activation-state reconciliation, operator bootstrap replay, authorized HTTP staging replay, Keycloak access-token validation, actor-context lookup, unprivileged HTTP administrator denial, current-session logout, disablement and unknown-key refresh after real provider signing-key rotation. The setup of the activation test account used the fixture Admin API to confirm verification and set a temporary test password directly at Keycloak. The test did not exercise SMTP delivery or authentication-action link consumption.

Provider outage and regression results are recorded with the delivery evidence. This continuation remains incomplete while superseded security scenarios lack equivalent regressions and required acceptance checks remain open.

The real actor endpoint returned 200 before the provider stop, 503 during the stop, and 200 after the same Keycloak instance restarted. The PostgreSQL suite ran 60 tests, with 35 passing, 25 failing and zero skipped. Failures came from six local authentication-flow cases, seven local credential cases, six security-regression cases that use the old sign-in/bootstrap path, and six local token-lifecycle cases. Those tests remain in the suite pending complete replacement coverage. The new provider actor and provisioning/lifecycle regressions passed.

Foundation passed 174 tests. Tooling passed 14 tests. OpenAPI release validation passed ten operations. Strict schema-v2 review-only dry-run passed. The CA-aware `composer audit --locked` exited 100 with Packagist CONNECT 403. Standard install, smoke and runtime failed at Debian TLS trust during image builds. Standard verify and consolidated full acceptance stopped at Composer TLS trust. The supported CA-aware installer successfully installed the final lockfile.

### Serialized lifecycle operations

The lifecycle endpoints and operator commands serialize invite, activate, bootstrap, suspend, disable and reactivate by platform ULID using a nonblocking PostgreSQL writer session lock. Configure the writer for direct or session-pooled connections. The lock is held across the bounded Keycloak call, with no open database transaction during that call. Competing calls fail with an operation-in-progress response. Reconciliation checks the current pending state again under the lock. A queued disablement from before a completed reactivation becomes a no-op.

| Operation | Local transition | Provider requirement |
|---|---|---|
| Invite | `staged_disabled` to `activation_pending` | Correlated account, required actions, then action email request. HTTP 204 records request acceptance. |
| Activate | `staged_disabled` or `activation_pending` to `active` | Same provider subject, verified email, enabled account and no outstanding required actions. |
| Suspend or disable | Eligible or pending account to local denial, then `suspend_pending` or `disable_pending` | Disable the provider account, revoke sessions, then mark `suspended` or `disabled`. |
| Reactivate | `suspended` or `disabled` to `reactivation_pending`, then `active` | Validate correlation, verified email and completed actions before enabling the provider account. Recheck provider state before restoring local eligibility. |

Run `php artisan schedule:work` in development or `php artisan schedule:run` every minute through the deployment scheduler. The reconciliation command is registered in `routes/console.php`. It retries pending reactivation and invite requests and checks activation state after the first acknowledged invite. Pending invite and reactivation rows wait at least one minute before another batch attempt. An acknowledgement does not certify SMTP delivery. End-to-end email action testing, terminal retry accounting, and provider-side handling of an HTTP mutation that completes after its client disconnects remain open.
