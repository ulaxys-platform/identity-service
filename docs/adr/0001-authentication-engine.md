# ADR-0001: Authentication engine selection

- Status: Accepted for implementation unit I1; full OAuth2/OIDC engine adoption re-evaluated at I2 gate.
- Date: 2026-11-26
- Deciders: Identity service implementation team

## Context

The uLaxys Identity service must issue verifiable tokens for hundreds of backend services and the API Gateway, support local authentication first (I1), and grow into standards-compliant OAuth 2.0 / OpenID Connect federation and machine identity flows (I2+). The generated foundation (P4.x template, PHP 8.5, Laravel 13, PostgreSQL) deliberately contains no authentication stack and no extra Composer dependencies beyond the framework.

## Decision

For unit I1 the service implements a **first-party, minimal token core** built exclusively on PHP core cryptography (`ext-openssl`, `password_hash`, `random_bytes`) with **no new Composer dependencies**:

- RS256-signed JWT access tokens (algorithm allowlist: RS256 only; `none` and symmetric algorithms are always rejected).
- Opaque, rotating refresh tokens with family tracking and reuse detection.
- JWKS publication (RFC 7517) with RFC 7638 thumbprint `kid` values.
- RFC 7662 introspection and RFC 7009 revocation contract endpoints for the API Gateway.
- Argon2id password hashing (see ADR-0003).

This keeps the frozen `composer install` and the template's Composer security audit green while the sandbox cannot run Composer. It is a *bridge*, not a protocol claim: the service does **not** advertise OAuth 2.0 or OIDC compliance in I1 and exposes no `/authorize` or public `/token` endpoint.

For I2 the selected engine is **Laravel Passport (league/oauth2-server)** as the OAuth 2.0 authorization server, evaluated in the real runtime, plus a thin first-party OIDC layer (discovery, JWKS, ID token, UserInfo) because Passport does not provide OIDC compliance out of the box. Adoption requires: `composer require laravel/passport`, lock refresh, `composer audit`, and the regression suite. The I1 signing keys (storage/keys + `identity_signing_keys` metadata) are designed to remain the JWKS source of truth so gateway validation survives the engine migration.

## Alternatives considered

| Option | Assessment |
| --- | --- |
| Laravel Passport immediately | Strong OAuth2 base (league/oauth2-server), but cannot be installed, locked or audited in this environment; unverified route registration in Laravel 13; would force simulated claims. Deferred to I2 with documented commands. |
| laravel/sanctum | SPA/mobile token convenience, not an authorization server; no JWKS/introspection/revocation contract for hundreds of services. Rejected. |
| External identity infrastructure (Keycloak, Ory, Auth0) | Materially improves protocol compliance but adds an independent runtime the platform must operate, conflicts with the mandate to preserve Laravel as the Identity foundation, and cannot be deployed or verified here. Rejected for now; federation *to* external OIDC providers stays in scope (I2). |
| firebase/php-jwt or web-token/jwt-framework | Maintained JWT libraries; would require composer lock changes impossible in this environment. Candidates for I2 consolidation; the I1 codec is intentionally small (RS256 only, strict claim validation) to lower migration cost. |
| Custom OAuth/OIDC protocol implementation | Forbidden by the task brief. Not done; the I1 token core implements no protocol grants beyond first-party session refresh. |

## Consequences

- Positive: frozen dependency install stays green; every crypto primitive is PHP core; token/JWKS/introspection contracts are testable against disposable PostgreSQL in CI.
- Negative: protocol-level OAuth/OIDC compliance is explicitly **deferred**, not simulated. The Gateway integration contract (docs/gateway-integration.md) documents exactly what I1 guarantees.
- Risk: a first-party codec must never grow grant logic. Any new grant, client type or OIDC claim must go through the I2 engine decision instead.
- Verification boundary: engine behavior is unit-tested locally; acceptance in the real repository CI is reported as NOT VERIFIED until run there.
