# Identity V2 roadmap — R0 approved

See [approved R0](r0-approved-architecture.md). R0 architecture ACCEPTED; technical PoC pending. R1 dependency/template gates; R2 provider refactor and review disposition; R3 lifecycle and mapping; R4 OIDC/session; R5 federation/MFA; R6 machine/Gateway/Access Management contracts; R7 OpenAPI/parity; R8 isolated local acceptance/migration; R9 independent security review. No phase implementation is claimed by this update.

## Historical candidate roadmap (superseded)

# Identity V2 roadmap and implementation status

Result classifications follow the final report: IMPLEMENTED / TESTED / VERIFIED / NOT VERIFIED / DEFERRED / BLOCKED. "TESTED" means the shipped suites cover the behavior; "VERIFIED" additionally requires a real run in the target environment (repository CI, Docker, disposable PostgreSQL), which this sandbox could not execute (no PHP/Composer/Docker runtime; see docs/acceptance.md).

## I0 — Foundation and architecture

- IMPLEMENTED: repository inspection, provenance check (`.ulaxys/generation.json`, template commit `5abad787`), security ADRs 0001-0005.
- IMPLEMENTED (code): reconstructed CI workflow, namespace-normalized reference parity, fixture-missing skip with exact blocker, runtime policy config (`config/identity.php`, `config/hashing.php`).
- BLOCKED (repository-side): `composer.lock` refresh for the verified Symfony 8.1.12 boundary (docs/dependency-security.md) — requires a PHP 8.5 + Composer runtime.
- NOT VERIFIED: first CI run of the reconstructed workflow.

## I1 — Core identities and authentication (this unit)

- IMPLEMENTED: users, user profiles, login identifiers (email/username), password credentials, one-time challenge tokens, sessions, refresh-token families, signing keys, audit/security events, interim platform administrator grants.
- IMPLEMENTED: local login, logout, refresh rotation with reuse detection, session listing/revocation (current/all/one), password change, enumeration-safe forgot/reset, bootstrap setup, email verification start/confirm, actor-context contract, JWKS/introspection/revoke Gateway contract endpoints.
- IMPLEMENTED: en/tr/ar localization, integration metadata for every mounted route, operator commands (`identity:keys:generate`, `identity:bootstrap-admin`).
- TESTED: Foundation suite (codec, policy, localization contract, route boundaries, integration metadata) and Database suite (migrations, flows, lifecycle, security regressions) shipped in `tests/`.
- NOT VERIFIED: executing those suites against a real disposable PostgreSQL; OpenAPI generation gate; Pint.

## I2 — External identity and authentication assurance

- DEFERRED: Google/Microsoft/generic OIDC federation (provider registry, discovery, PKCE, state/nonce, ID-token validation, secure linking), TOTP MFA with encrypted secrets and single-use recovery codes, passkeys via `web-auth/webauthn-lib`. Design boundaries are pre-drawn in docs/identity-providers.md, docs/mfa.md, docs/passkeys.md and docs/oauth-oidc.md. Real provider acceptance will be NOT VERIFIED until run against actual providers (a controlled mock OIDC server is planned for automated protocol tests).

## I3 — Organization and access management (historical proposal; not approved)

Authorization belongs in a separate Access Management ownership boundary per the PR remediation direction. Organization/department record ownership remains open. Do not implement the historical Identity-local scope below; consult the central `ulaxys-docs` ADRs and `docs/review-remediation.md`.

- DEFERRED: organizations, departments, memberships, scoped RBAC (roles/permissions/role-permissions/assignments), permission catalog import (idempotent, dry-run, conflict detection, no automatic grants), authorization decision engine (single/batch/any-of/all-of/scope-aware, deny-by-default). docs/authorization-model.md sketches the schema and engine contract; the interim platform-administrator gate is migrated away here.

## I4 — External and machine access

- DEFERRED: invitations, delegation and temporary access, service principals, OAuth clients, external resource scope integration (docs/invitations.md, docs/delegation.md, docs/service-identities.md, docs/resource-scopes.md).

## I5 — Gateway integration and hardening

- PARTIALLY IMPLEMENTED: actor-context contract, JWKS, introspection and revocation already exist (docs/gateway-integration.md). DEFERRED: full authorization API, batch evaluation, distributed coordination and revocation notifications over Redis.

## I6 — Acceptance and release readiness

- DEFERRED/BLOCKED: full security regression on real infrastructure, Docker acceptance, multi-instance acceptance, scale benchmarks (scripts and methodology in docs/benchmarking.md), deployment handoff finalization (docs/handoff.md).
