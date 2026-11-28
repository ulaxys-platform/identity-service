# OAuth 2.0 and OIDC capabilities

Status: I1 implements NO public OAuth/OIDC protocol endpoints — by explicit decision (ADR-0001). Claiming compliance without an engine would simulate standards behavior.

## Implemented today (Gateway contract, not protocol)

- Access tokens (RS256 JWT) with issuer/audience/subject/session claims.
- Refresh-token rotation with reuse detection (first-party semantics, not an OAuth grant).
- RFC 7662-shaped introspection, RFC 7009-style revocation, RFC 7517 JWKS — internal Signature-gated endpoints under `/api/v1/oauth/*`.

## Deferred to I2 (with the selected engine)

- Authorization Code + PKCE, Client Credentials, server metadata, OpenID Connect discovery, ID tokens, UserInfo.
- Engine decision: Laravel Passport (league/oauth2-server) evaluated in the real runtime; Passport does not provide OIDC compliance out of the box, so discovery/ID-token/UserInfo will be a thin first-party layer above it.
- Deprecated flows (implicit, ROPC) will NOT be enabled for new clients; redirect URIs use exact matching; client registration is an authorized operator operation, never open dynamic registration.
- Protocol endpoints will not be wrapped in the uLaxys response envelope where that would violate the protocol; deviations are documented per template policy.
