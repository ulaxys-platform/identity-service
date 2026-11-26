# Scoped RBAC and authorization engine

Status: HISTORICAL PROPOSAL, NOT APPROVED for implementation in Identity. The PR remediation direction separates Access Management ownership; central ADR approval remains required. The schema below is reference material only. I1 ships only the interim platform-administrator gate (ADR-0004) and explicit permission *declarations* (not grants) in `config/integration.php`. Nothing in I1 grants permissions to users.

## Pre-drawn schema (must be preserved when implementing)

- `roles`, `permissions` (stable keys like `inventory.products.read` from the existing subject/action/module declarations), `role_permissions`, `role_assignments` (user/principal, role, resource scope, valid-from/until, status, assigned-by, reason, revocation metadata), `resource_scopes` (type + authoritative external reference), `permission_catalogs` (version, digest, lifecycle).
- Engine: single + batch evaluation, any-of/all-of checks, effective-permission retrieval with bounded pagination, scope-aware decisions, expired/revoked assignment rejection, suspended-principal rejection; deny-by-default; dependency failure never yields allow; 401/403/429/503 semantics per the task brief.
- Cache: keys include principal, permission, resource, and policy-version dimensions; explicit invalidation and bounded staleness.
- Privilege protection: grant authority separated from operational permissions; safeguards against self-granting, escalation, scope expansion, cross-organization grants, last-admin removal, conflicting delegations; no undocumented super-admin bypass; protected system roles where justified.
