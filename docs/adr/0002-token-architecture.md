# ADR-0002: Token architecture and revocation semantics

- Status: Accepted
- Date: 2026-11-26

## Context

Access tokens must be validated by the API Gateway and hundreds of backend services. Users may hold hundreds of assignments, so tokens must stay small. Refresh token replay must be detectable, and revocation must take effect without waiting for token expiry.

## Decision

**Access tokens** are RS256 JWTs (10 minutes default TTL, configurable) with claims:

- `iss`: configured stable issuer (`identity.issuer`, derived from `APP_URL` when unset).
- `aud`: explicit platform audience string (`identity.token.audience`). The Gateway must validate it; introspection echoes it.
- `sub`: opaque user ULID. No roles, organizations, departments or commercial accounts are embedded.
- `sid`: auth session ULID; validation loads the session and rejects revoked/expired sessions, giving near-immediate revocation.
- `amr`: authentication methods used (e.g. `["password"]`), plus `auth_time`, `jti`, `iat`, `nbf`, `exp`.

**Refresh tokens** are 48-byte random secrets (base64url), stored only as SHA-256 hashes. Every rotation belongs to a `family_id` rooted at the session. On successful rotation the presented row is consumed and a new row is appended. If a consumed or revoked row is presented again the whole family is revoked, the session is revoked, and a security event is written; the response is a generic 401.

**Validation**: only RS256; `kid` must match a published key; the signature is verified with `openssl_verify`; `exp`/`nbf` are checked with a bounded 30-second clock skew; `iss` and `aud` are checked exactly. Unsigned JWTs are structurally impossible to accept.

**Revocation endpoints** (Signature-gated, internal): RFC 7662 introspection returns the claim set with `active` reflecting token validity *and* session validity; RFC 7009-style revoke revokes the session and its refresh family.

Key material lives in `IDENTITY_KEYS_PATH` (mounted from a secret manager in production, never committed); `identity_signing_keys` holds metadata (`kid`, paths, active/retired state). JWKS publishes active plus recently retired keys for rotation overlap. Rotation is performed by `identity:keys:generate --rotate` during a maintenance window; emergency invalidation is documented in docs/token-lifecycle.md.

## Consequences

- Tokens stay small; authorization lookups remain server-side (RBAC engine arrives in I3).
- Session-side validation makes revocation immediate but adds one indexed query per authenticated request; caching strategy is deferred until measured.
- Private keys never leave the host; JWKS publishes public keys only.
