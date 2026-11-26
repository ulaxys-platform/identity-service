# PR #1 independent review remediation

Status: MERGE BLOCKED. Reviewed candidate 3ce0fe0d0515d135a3f975bf1d84098bb47880fd. No production approval.

## Ownership and engine
- Identity owns authentication identities, credentials, sessions, federation, assurance and machine authentication.
- Separate Access Management ownership boundary for roles, permissions, scoped grants and delegation. Do not implement organization/department master data or a broad IAM subsystem here. Organization/department authority remains open. Central ulaxys-docs decisions govern.
- Keep Business Partner commercial accounts independent; Reference owns reference data and Foundation owns numbering. Gateway owns traffic and verified actor propagation; resource services enforce resource-specific decisions.
- No engine decision has been accepted. Existing JwtCodec is a review candidate only. Evaluate maintained Laravel OAuth/OIDC solution (Passport alone is not OIDC) versus Keycloak in actual PHP 8.5/Laravel 13/Docker infrastructure. Select and document an engine before accepting I1; do not implement a new OIDC protocol or retain duplicate credential authorities. Replace the custom codec with maintained library/engine implementation before merge.

## Security disposition
- IDV2-001: candidate patch serializes refresh rotation per session and conditionally consumes tokens; rotation failure rolls back and replay revocation commits before denial. Real concurrent PostgreSQL regression required, including revoke/refresh and signing failure.
- IDV2-002: engine choice reopened; implementation replacement pending runtime evaluation.
- IDV2-003: targeted Composer lock update and clean audit pending actual Composer runtime. Never suppress advisories.
- IDV2-004: candidate patch uses checked conditional token consumption within the same transaction as credential/verification side effects. Concurrent PostgreSQL tests required; additionally review multiple distinct recovery challenges and lifecycle changes.
- IDV2-005: candidate patch serializes bootstrap in PostgreSQL and rejects a different initial administrator after any grant exists. Lifecycle/recovery and real concurrent acceptance remain open.
- IDV2-006: candidate patch serializes key metadata publication in a transaction and exclusively writes key files with restrictive permissions before publishing metadata. Orphan file reconciliation, shared secret storage, overlap/TTL bounds, real rotation tests and final engine key lifecycle remain open.
- Additional: introspection now checks audience, subject/session ownership and current user eligibility; revoke and unavailable-key response paths corrected. Regression tests added (not executed). Normalized identifier throttling and production rejection of plaintext delivery driver candidate patches. Verify IP/account-wide limits, distributed cache and no secret logging.

## Acceptance
GitHub Actions usage quota reported exhausted by Product Owner. CI must remain enabled and unchanged; quota is not a test pass. Independently execute the following on the exact PR HEAD with disposable infrastructure:
1. Verify generation provenance, inspect expected consumer deviations, and compare workflow with official template.
2. Run targeted compatible Composer dependency updates and commit lockfile; run composer validate --strict, composer audit and platform requirements.
3. Run .\tools install, .\tools verify, .\tools smoke (PowerShell) and equivalent documented foundation/database/OpenAPI/integration gates. Record commands, exit codes, skips, environment and SHA; no secrets.
4. Run deterministic concurrent refresh, reset, setup, verification, key rotation and bootstrap tests using separate PostgreSQL connections/processes. A sequential replay test is insufficient.
5. Test Gateway contract, token revocation, authentication assurance boundaries, error handling and secret history. Confirm no legacy production DB is touched.
6. Independent security review of selected engine integration and all remaining findings. No merge until Product Owner approval.

No PHPUnit, Composer audit, PHP 8.5 or Docker execution is claimed by this remediation.
