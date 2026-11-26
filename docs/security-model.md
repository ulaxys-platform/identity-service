# Security model

## Trust boundaries

- The static `Signature` header proves possession of a shared service secret. It authenticates *service transport*, not end-user identity or permission — this limitation is inherited from the template and stated honestly (docs/gateway-integration.md). mTLS/workload identity compatibility is a design goal, not yet implemented.
- End-user identity comes exclusively from a verified RS256 access token bound to a live session. Caller-supplied headers (`X-Actor-*`, `X-User-*`) are never trusted and are covered by a regression test.
- Deny-by-default everywhere: missing/invalid tokens 401, insufficient grant 403, dependency failure 503, rate limit 429; 406 is never used for forbidden access.

## Threat coverage implemented in I1

- Credential stuffing / brute force: per-identifier+IP rate limiting on login, recovery and refresh (hashed cache keys).
- Username enumeration: identical denial bodies, dummy-hash timing equalization, enumeration-safe recovery responses (regression-tested).
- Session fixation: no classic PHP sessions are used for API identity; sessions are server-side rows created only after successful verification.
- Token replay: single-use rotating refresh tokens with family revocation (regression-tested).
- Insecure reset: hashed single-use short-lived tokens, policy-checked new passwords, all-sessions revocation.
- IDOR: owner-scoped session operations; cross-user access returns 404 (regression-tested).
- Mass assignment: shared ApiFormRequest rejects unknown top-level fields, invalid UTF-8 and NUL text (template behavior, regression-tested).
- Secret leakage: passwords/tokens never logged; audit contexts are whitelist-built; IP/UA stored keyed-hashed; `RedactSecrets` logging processor retained from the template.
- Authorization dependency failure: signing-key unavailability yields 503 on login and token validation — never silent success.

## Deferred controls (documented, not simulated)

MFA, passkeys, external OIDC federation, scoped RBAC and privilege-escalation controls (self-grant, scope expansion, cross-organization grants, last-admin removal) are I2/I3/I4 work with pre-drawn designs in the corresponding docs. No undocumented super-admin bypass exists: the interim platform-administrator grant is created only by the auditable bootstrap command.
