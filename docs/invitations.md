# Invitations

Status: DEFERRED to I4. I1 creates invited users without passwords only through admin creation or the bootstrap command; no invitation flow exists and none is simulated.

## Pre-drawn design (must be preserved when implementing)

- Organization/department/scoped external invitations with role-assignment proposals, expiration, single-use acceptance, cancellation, resend policies, existing-user and new-user paths.
- Acceptance verifies intended recipient and scope; arbitrary emails supplied during acceptance are never trusted.
- Tokens: high-entropy, bounded lifetime, stored as hashes (same pattern as `identity_credential_challenges`).
- Invitations never bypass authentication, MFA or grant-authority policies; email transport abstraction identical to `TokenDeliveryInterface`.
