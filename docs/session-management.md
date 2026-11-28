# Session and device management (I1)

Status: IMPLEMENTED and TESTED.

## Model

`identity_auth_sessions` rows are first-class sessions: ULID id, user, device label (informational), keyed IP/user-agent hashes, `authentication_methods` JSON, `mfa_completed_at` (reserved for I2), `last_seen_at` (touched on every authenticated request), absolute `expires_at` (`identity.session.lifetime_hours`, default 12), `revoked_at`/`revoked_reason`. Access tokens carry `sid`; a revoked or expired session invalidates its tokens immediately.

## Endpoints

- `GET /api/v1/sessions` — own active sessions with `isCurrent` flag.
- `DELETE /api/v1/sessions/current` — logout of the current device.
- `DELETE /api/v1/sessions/all` — logout from all other devices (refresh families revoked except the current session's).
- `DELETE /api/v1/sessions/{sessionId}` — revoke one own session; other users' sessions are indistinguishable from nonexistent ones (404).

## Lifecycle coupling

Password change revokes all sessions except the current one; password reset revokes all sessions. Account lifecycle (suspended/disabled/archived) denies login and invalidates token validation immediately because the user is re-loaded per request. Device metadata is informational and stored hashed; it never authenticates anything.
