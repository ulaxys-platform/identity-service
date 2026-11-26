# Permission catalog

Status: declarations IMPLEMENTED for this service (every mounted Identity route carries subject/action/module metadata with tr/en/ar descriptions and explicit exemptions in `config/integration.php`, validated by IntegrationMetadataTest); the import engine, lifecycle, catalog versioning and grant isolation are DEFERRED to I3.

## Compatibility with the template mechanism

The existing subject/action/module declaration shape (consumed by `ulaxys:generate-integration`, `ulaxys:generate-permissions` and the native Identity renderer) is preserved unchanged — the Identity V2 modules are registered as ordinary `modules` entries, so the generators produce declarations without modification. Import must be idempotent with dry-run, conflict detection, added/removed permission detection, description updates, deprecation and history; imported permissions never grant anything to anyone; imported PHP code is never executed.
