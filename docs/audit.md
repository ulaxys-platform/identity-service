# Audit and security events

Status: IMPLEMENTED and TESTED (append-only write paths, transaction-coupled); retention job DEFERRED.

## Stores

- `identity_audit_events`: administrative/domain actions (actor, actor type, action, subject, result, correlation id, context, occurred at).
- `identity_security_events`: authentication lifecycle (login success/failure with reason, credential change/reset, session revocation, refresh reuse, bootstrap, email verification).

Both are written inside the same database transaction as the state change they describe. Application code has no update or delete paths; the repository contract exposes append-only methods (ADR-0005).

## Captured events (I1)

`identity.auth.login`, `identity.auth.logout`, `identity.session.revoke|revoke_current|revoke_all_others`, `identity.credential.password_changed|password_reset|email_verified`, `identity.user.created`, `identity.profile.updated`, `identity.bootstrap.admin_created|admin_bootstrap_idempotent|account_activated` and security events `auth.login.success|failure`, `auth.token.refresh.failure`, `auth.session.revoked|revoked_all`, `auth.credential.changed|reset`, `auth.recovery.requested`, `auth.email_verification.*`, `auth.account.activated`, `auth.bootstrap.admin_created`.

## Privacy and integrity

Contexts are whitelist-built; regression tests prove passwords and attempted passwords never reach the stores. IP addresses and user agents are stored as keyed SHA-256 hashes (`hash_hmac` with the `APP_KEY` pepper). Retention policy: operator-configured, default target 400 days; the purge job and database-role hardening (no UPDATE/DELETE on these tables for the application role) are deployment requirements documented in docs/deployment.md.
