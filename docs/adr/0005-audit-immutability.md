# ADR-0005: Audit and security event immutability

- Status: Accepted
- Date: 2026-11-26

## Context

Security and audit history must survive unauthorized modification, must never contain credentials, and must correlate authentication decisions with sessions and correlation IDs.

## Decision

- Two append-only stores: `identity_audit_events` (administrative/domain actions) and `identity_security_events` (authentication lifecycle: login success/failure, credential change, reset, session revocation, refresh reuse, bootstrap).
- Records are written inside the same database transaction as the state change they describe, so audit cannot silently diverge from state; failures roll back together.
- Application code never updates or deletes events; no management endpoint exposes mutation. Database-level tamper protection (minimal-privilege role without UPDATE/DELETE on these tables, plus retention policy) is a deployment requirement documented in docs/deployment.md and docs/audit.md.
- Sensitive values are structurally excluded: context payloads are built from explicit whitelisted fields; IP addresses and user agents are stored only as keyed SHA-256 hashes (pepper from `APP_KEY`, `hash_hmac('sha256', ...)`); passwords, tokens, signature values, and future TOTP/passkey material are never passed to the logger.
- Retention and privacy policy (default 400 days, operator-configurable) is documented; no retention job ships in I1.

## Consequences

- Audit rows grow monotonically; table partitioning is listed in docs/database.md as a later scalability step.
- Hashed IPs trade investigator convenience for privacy; correlation uses session and correlation IDs instead.
