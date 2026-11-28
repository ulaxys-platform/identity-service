# Scoped RBAC and authorization engine

> R0 update (2026-11-27): Product Owner approved self-hosted Keycloak as sole credential/token authority, Laravel Identity as platform ULID/profile/lifecycle/mapping authority, and separate Access Management. Historical candidate design below is superseded; see R0 implementation direction and canonical ADR-0015 in https://github.com/ulaxys-platform/ulaxys-docs/pull/3. No runtime acceptance implied.

Status: HISTORICAL PROPOSAL, NOT APPROVED for implementation in Identity. The PR remediation direction separates Access Management ownership; central ADR-0015 approves separate ownership; detailed schema approval remains required. The schema below is reference material only. I1 ships only the interim platform-administrator gate (ADR-0004) and explicit permission _declarations_ (not grants) in `config/integration.php`. Nothing in I1 grants permissions to users.

## Historical proposed schema (reference only)

- `roles`, `permissions` (stable keys like `inventory.products.read` from the existing subject/action/module declarations), `role_permissions`, `role_assignments` (user/principal, role, resource scope, valid-from/until, status, assigned-by, reason, revocation metadata), `resource_scopes` (type + authoritative external reference), `permission_catalogs` (version, digest, lifecycle).
- Engine: single + batch evaluation, any-of/all-of checks, effective-permission retrieval with bounded pagination, scope-aware decisions, expired/revoked assignment rejection, suspended-principal rejection; deny-by-default; dependency failure never yields allow; 401/403/429/503 semantics per the task brief.
- Cache: keys include principal, permission, resource, and policy-version dimensions; explicit invalidation and bounded staleness.
- Privilege protection: grant authority separated from operational permissions; safeguards against self-granting, escalation, scope expansion, cross-organization grants, last-admin removal, conflicting delegations; no undocumented super-admin bypass; protected system roles where justified.
