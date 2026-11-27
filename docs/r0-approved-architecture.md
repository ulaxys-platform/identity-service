# R0 — approved Identity V2 architecture

Date: 2026-11-27. Product Owner explicitly approved R0-01–15. Canonical Turkish ADR-0015: https://github.com/ulaxys-platform/ulaxys-docs/pull/3 (open documentation PR). Continue existing Identity PR #1, branch feature/identity-v2-delivery. Reviewed pre-decision head: 1549e3e2506f675d0a536c2965d2f254a7542b53. No replacement PR or automatic merge.

| ID | Approved decision |
|---|---|
| R0-01 | Self-hosted Keycloak authentication engine |
| R0-02 | Preserve Laravel 13 / PHP 8.5 Identity service and template |
| R0-03 | Separate Access Management service |
| R0-04 | Immutable platform user ULID |
| R0-05 | Keycloak sole credential authority |
| R0-06 | Keycloak sole token authority |
| R0-07 | OIDC Authorization Code + PKCE for frontend |
| R0-08 | OAuth 2.0 Client Credentials for service authentication |
| R0-09 | Google and Microsoft federation |
| R0-10 | TOTP and WebAuthn |
| R0-11 | Independent database per service |
| R0-12 | Gateway JWKS + authoritative introspection where required |
| R0-13 | Reference country / locale integration |
| R0-14 | Parallel controlled legacy migration |
| R0-15 | Continue existing PR #1 |

Identity owns platform ULID, profile, lifecycle policy, unique issuer/subject mapping, provider provisioning/reconciliation and platform audit. Keycloak owns login credentials, authentication action tokens, sessions, access/refresh tokens, signing keys, OAuth clients, federation and MFA. Use supported Admin API and standard protocols, never direct Keycloak DB access. No duplicate Laravel password/session/refresh/signing key/token issuance authority. No frontend password proxy through Laravel. Never auto-link on email alone. Access Management owns roles, permissions, scoped grants and delegation; organization/department master ownership and inheritance remain open. Domain services retain resource authorization. Business Partner is independent; Reference owns country/locale definitions; Foundation owns numbering.

Preserve useful User/Profile, DTOs, repositories, audit, localization and template/tests. Replace custom JwtCodec, TokenService and duplicate authentication persistence through reviewed migrations after PoC. All IDV2-001–016 findings require fix or justified removal with replacement regression evidence. Existing code is unaccepted candidate; no legacy DB changes.

Next technical PoC: pin supported Keycloak version/image digest; isolated Docker/PostgreSQL and PHP 8.5/Laravel 13; verify discovery, PKCE S256, client credentials, issuer/subject mapping, provisioning/reconciliation, disable/revoke, key rotation, least-privilege Admin API, TLS/hostname, secret handling, failure/recovery. Gateway validates issuer/audience/signature/expiry and maps trusted actor; JWKS alone cannot guarantee immediate revocation. Define bounded staleness and fail-closed behavior. Realm/client topology, BFF/token storage, machine mapping, HA/backup/restore, delivery and migration remain open. Real federation acceptance needs actual providers.

Keep Composer audit, template parity, local install/verify/smoke, real PostgreSQL concurrency, OpenAPI and independent security review. Hosted CI quota is not a pass. Inspect legacy abilities/authenticated-actor-context consumers; preserve parallel operation. No production changes or merge before PO acceptance.
