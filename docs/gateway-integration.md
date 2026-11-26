# API Gateway integration contract (version 1)

Status: IMPLEMENTED contract endpoints (actor context, JWKS, introspection, revocation); the full authorization API is DEFERRED to I5. This document is the independent handoff for the Gateway team.

## Trust model

The Gateway authenticates to Identity with the template's static `Signature` header (`config('services.internal.signature')`) over private networking. This proves possession of a shared secret only — no request integrity or replay protection. Treat it as transport authentication, not authorization. Identity never trusts Gateway-forwarded user headers; the Gateway must obtain actor identity from a token it validated or from `/api/v1/actor/context`.

## Token validation

Access tokens are RS256 JWTs. The Gateway validates locally or introspects:

- **Local validation (JWKS)**: `GET /api/v1/oauth/jwks` returns the RFC 7517 JWK set (public keys only) with RFC 7638 `kid` thumbprints. Validate: RS256 only, `kid` match, signature, `exp`/`nbf` (bounded skew), `iss` = configured issuer (`IDENTITY_ISSUER`), `aud` = `IDENTITY_TOKEN_AUDIENCE`, plus `sub`/`sid` presence. Local validation alone does NOT detect revoked sessions; combine with introspection or accept the bounded staleness documented here.
- **Introspection (authoritative)**: `POST /api/v1/oauth/introspect` `{token}` → RFC 7662-shaped result; `active=false` for unknown/invalid/expired tokens and revoked/expired sessions.

## Actor context

`GET /api/v1/actor/context` (Bearer + Signature) returns:

```json
{"size":1,"status":200,"message":"OK","items":{
  "actorId":"opaque-ulid","actorType":"user","sessionId":"opaque-ulid",
  "organizationId":null,"authenticationMethods":["password"],"authorizationVersion":1}}
```

`authorizationVersion` is opaque and bumped on contract changes. `organizationId` is reserved for I3 and always null in I1. Errors: 401 invalid/missing token, 403 missing signature, 503 signing-key dependency unavailable.

## Revocation

`POST /api/v1/oauth/revoke` `{token}` → always 200 (RFC 7009 semantics); revokes the session and its refresh family.

## Rate limits and errors

Introspection is rate-limited per source. All endpoints use the template error envelope `{"status","code","message"}`; 406 is never used. Gateway-level authorization does not replace downstream service authorization decisions (I5 authorization API will add batch evaluation).

## What this contract does NOT yet provide

Authorization decisions (permission/scope checks), batch evaluation, service-principal actor types, OIDC discovery/ID tokens/UserInfo. These are pre-designed in docs/authorization-model.md, docs/service-identities.md and docs/oauth-oidc.md and scheduled for I2/I3/I5.
