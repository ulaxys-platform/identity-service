# Delegated and temporary access

Status: DEFERRED to I4.

## Pre-drawn design (must be preserved when implementing)

- Delegation source, delegator, delegate, permitted roles/permissions, resource scope, validity window, revocation, depth policy, audit records.
- Delegation cannot exceed the delegator's grant authority; unrestricted impersonation is forbidden — if administrative impersonation is ever considered, it requires a separate security ADR, explicit actor/subject distinction, strong reauthentication and comprehensive audit (deferring it is acceptable).
