# Local authentication (I1)

Status: IMPLEMENTED and TESTED (unit tests shipped); runtime VERIFICATION pending real-environment runs.

## Flows

- **Login** `POST /api/v1/auth/login` (Signature-gated, rate-limited per identifier+IP): resolves email/username login identifiers against `identity_login_identifiers`, verifies Argon2id credentials, checks account lifecycle, creates a session, issues an access token + rotating refresh token, and writes audit + security events inside the same transaction. Unknown identifier, missing credential, wrong password and inactive account all return the identical 401 body; a dummy Argon2id verification equalizes timing when no credential exists (ADR-0003).
- **Refresh** `POST /api/v1/auth/refresh`: presents the opaque refresh token; consumed/revoked presentation is treated as replay, revoking the whole family and the session (401 generic response).
- **Logout** `POST /api/v1/auth/logout`: revokes the current session and its refresh family.
- **Setup** `POST /api/v1/auth/setup`: consumes the single-use bootstrap token, stores the first Argon2id hash and activates the invited account.
- **Password change** `POST /api/v1/credentials/password`: requires the current password; policy-checks the new one; revokes all other sessions and refresh families.
- **Forgot/Reset** `POST /api/v1/credentials/forgot-password|reset-password`: identical responses regardless of account existence; single-use hashed tokens (60 minutes) delivered via `TokenDeliveryInterface`; reset revokes all sessions.
- **Email verification** `POST /api/v1/credentials/email-verification/start|confirm`.

## Password policy

NIST SP 800-63B aligned: 12-128 characters, static blocklist, rejection of identifier/display-name-derived passwords, no forced composition rules (ADR-0003). Violation messages are localized en/tr/ar.

## Assurance levels

I1 records `amr: ["password"]` and `auth_time` on tokens and sessions. Step-up authentication (reauthentication for sensitive operations), MFA and passkey assurance arrive with I2; the session schema reserves `mfa_completed_at` for that purpose.
