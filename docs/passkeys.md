# Passkeys and WebAuthn

Status: DEFERRED to I2/I4. No custom WebAuthn cryptography will be written; the planned library is `web-auth/webauthn-lib` (maintained, PHP 8.x compatible) pending real-runtime evaluation.

## Pre-drawn design (must be preserved when implementing)

- Credential registration/authentication flows with challenge expiration, replay protection, RP-ID and origin validation, and configurable user-verification policy.
- Multiple credentials per user with names and per-credential revocation.
- Platform/browser compatibility documented at implementation time; automated protocol tests explicitly distinguished from real browser/authenticator acceptance.
