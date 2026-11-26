# External identity providers

Status: DEFERRED to I2. Real Google/Microsoft acceptance will be NOT VERIFIED until provider credentials and live acceptance exist; automated tests will use a controlled mock OIDC server.

## Pre-drawn design (must be preserved when implementing)

- Provider registry with trusted issuer allowlist, client IDs, secret *references* (never secrets in DB), discovery metadata caching, approved authorization/token endpoints and exact redirect-URI matching.
- State + nonce + PKCE on every browser flow; ID-token validation against the provider's JWKS with strict issuer/audience; replayed authentication responses rejected via nonce/state single-use storage.
- Uniqueness of external identities keyed by (verified provider issuer, provider subject) — never by email. Two providers returning the same email never auto-link accounts; linking requires an authenticated, explicit flow.
- Provider access tokens are never treated as uLaxys access tokens.
- Attack coverage required by the task brief: account takeover, login CSRF, authorization-code substitution, open redirects, provider mix-up, discovery SSRF (endpoints allowlisted), invalid issuer/audience acceptance.
