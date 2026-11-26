# Token lifecycle (I1)

Status: IMPLEMENTED and TESTED; VERIFICATION pending real-environment runs.

## Access tokens

RS256 JWTs (10 minutes default): `iss` (stable issuer), `aud` (single platform audience), `sub` (opaque user ULID), `sid` (session ULID), `amr`, `auth_time`, `jti`, `iat`, `nbf`, `exp`. No roles, organizations or commercial accounts are embedded; authorization stays server-side. Validation enforces: RS256-only algorithm allowlist, `kid` match against published keys, `openssl_verify` signature, bounded 30-second clock skew, exact issuer/audience.

## Refresh tokens

48-byte random secrets, stored only as SHA-256 hashes. Every rotation belongs to a `family_id`; rotation consumes the presented row and appends a new one. Presenting a consumed or revoked row is replay: family + session revoked, security event written, generic 401 response.

## Revocation semantics

Session-side validation gives near-immediate revocation: every authenticated request re-loads the session and rejects revoked/expired rows. `POST /api/v1/oauth/revoke` (Signature-gated) revokes the session and family (RFC 7009 semantics: always 200). `POST /api/v1/oauth/introspect` returns `active` reflecting token AND session state (RFC 7662 semantics).

## Key management

PEM material lives in `IDENTITY_KEYS_PATH` (secret-manager mount in production); `identity_signing_keys` stores metadata only. `php artisan identity:keys:generate` creates the first key; `--rotate` publishes a new key and retires the old one with JWKS overlap (`identity.keys.retired_publication_days`, default 7). Emergency invalidation: rotate with overlap removal (`retired_publication_days=0` publishes only the active key) — every token signed by the retired key fails validation immediately; do this only during a maintenance window because all users must re-login or refresh.

## Known boundaries

- No OAuth2 grant machinery: `/authorize` and public `/token` do not exist in I1 (ADR-0001).
- ID-token issuance and UserInfo are I2 engine work; the Gateway contract here covers access tokens only.
