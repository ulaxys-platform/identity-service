# Multi-factor authentication

Status: DEFERRED to I2. No TOTP/WebAuthn/recovery-code code exists in I1; nothing simulates MFA state.

## Pre-drawn design (must be preserved when implementing)

- TOTP via a maintained library (RFC 6238); secrets encrypted at rest (Laravel encryption, key from `APP_KEY`); enrollment inactive until confirmed; challenge rate-limited; previously accepted TOTP windows rejected to prevent replay.
- Single-use recovery codes stored as hashes; reuse impossible.
- Disablement requires an authenticated, MFA-verified session — an unauthenticated user can never disable MFA.
- Policy matrix (administrators, organization members, external users, privileged access, sensitive operations) configurable per audience; SMS is never the primary privileged-user mechanism.
- Session schema already reserves `mfa_completed_at`; `amr` claims will carry `["password","totp"]` / `["pwd","webauthn"]` style values; actor-context `authenticationMethods` already surfaces them.
