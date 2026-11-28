# Service identities and machine access

Status: DEFERRED to I4. I1 treats every login-eligible account as human (`identity_users.type = human`; the `service` type value is reserved but has no login path).

## Pre-drawn design (must be preserved when implementing)

- Service principals for API Gateway, Inventory, Sourcing, Order, Accounting, Media, HS Code, Data Service, Business Partner Service — stable identifiers, OAuth-client association where appropriate, approved audiences, service scopes, credential lifecycle (creation, expiration, rotation, revocation, enable/disable), audit history.
- Machine identities are never normal human accounts; no shared unrestricted platform credentials.
- Compatibility boundary for the existing static Signature mechanism is documented honestly (shared-secret transport authentication without integrity/replay protection — docs/gateway-integration.md); mTLS/workload-identity compatibility is a design goal, not yet implemented.
