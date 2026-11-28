# External resource scope contract (design, DEFERRED to I4)

Status: DEFERRED — no code, no fake records. Identity never invents commercial resources (master accounts, operational accounts, legal parties); Business Partner and the future Data Service remain authoritative.

## Required operations (to be implemented against real services)

- `VerifyResource(resourceService, resourceType, resourceId)` — existence and ownership.
- `ResolveScope(resourceService, resourceType, resourceId)` — canonical scope identifier.
- `VerifyHierarchy(parent, child)` — explicit, policy-gated inheritance evidence.
- `VerifyRelationship(user, resource, relationshipType)` — relationship evidence (accountant link, broker link, employment).
- `OnResourceRetired(resource)` — invalidation trigger for grants scoped to the resource.

## Staleness and failure policy (design)

- Authorization that depends on external verification MUST fail closed when the authoritative service is unavailable (503 dependency failure), never succeed on stale data.
- Cached verification results carry explicit TTLs and version dimensions; maximum staleness is operator-configured.
- Scope references use the documented shape `{"resourceService","resourceType","resourceId"}` with opaque stable identifiers owned by the authoritative service — never commercial code strings.
- No production test adapter automatically authorizes external resources; controlled test doubles live only in test environments.
