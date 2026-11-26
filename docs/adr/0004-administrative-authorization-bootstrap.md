# ADR-0004: Administrative authorization and platform administrator bootstrap

- Status: Accepted (interim until the scoped RBAC engine, unit I3)
- Date: 2026-11-26

## Context

The platform forbids a single global role column and automatic escalation, but the scoped RBAC engine (roles, permissions, resource scopes) is scheduled for I3. Unit I1 still needs a controlled way to administer users.

## Decision

- A dedicated `identity_platform_administrators` table grants a bounded interim capability: user administration endpoints require an active, non-revoked row for the authenticated actor (`RequirePlatformAdministrator` middleware after token validation). It is **not** a role column on users, grants no other permission, and is checked deny-by-default.
- Creation happens only through the auditable, idempotent `identity:bootstrap-admin` operator command: no seeded passwords, no SQL-inserted administrators, no undocumented bypass. The command creates the user in `invited` state, prints a single-use high-entropy setup token **once**, and records audit events. Re-running for an existing active administrator changes nothing and exits successfully. The command refuses to escalate an existing user that has no grant.
- Last-administrator removal, self-grant, scope expansion and delegation are structurally impossible in I1 because no grant endpoints exist; they become explicit requirements of the I3 engine (docs/roadmap.md).
- Every management route carries an explicit permission declaration or an exemption reason in `config/integration.php`, so the eventual RBAC engine and the existing Identity permission generators consume a complete, reviewable catalog.

## Consequences

- I1 authorization is intentionally minimal; importing permission catalogs and granting permissions remain I3 work and are **not** simulated.
- The interim table is migrated/dropped in I3 with a documented data migration path.
