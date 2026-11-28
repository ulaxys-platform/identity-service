# Template defects and dependency security disposition

- Date: 2026-11-26
- Scope: generated identity-service baseline (template commit `5abad787`, generator P4.3 snapshot provenance in `.ulaxys/generation.json`)

This document records the template-level issues investigated for the Identity V2 unit, the evidence found in the actual checkout, and what was changed or deliberately left untouched. Source evidence, not assumptions, drives every disposition.

## 1. Consumer CI workflow excluded through `.github export-ignore`

Evidence:

- `.gitattributes` contains `/.github export-ignore` and `CHANGELOG.md export-ignore`.
- `.ulaxys/generation.json` records `snapshot: git-archive`; the generated repository therefore never received the template's `.github` directory.
- The repository root listing contains no `.github` directory.
- `README.md` and `docs/new-service.md` both document a GitHub Actions workflow that runs Composer validation and security audit, PHP syntax, Pint, PHPUnit Foundation and Database, the OpenAPI gate, strict generator dry-run, the production image build and `tests/Runtime/smoke-container.py`.

Disposition: reconstructed `.github/workflows/ci.yml` from the documented check list and the actual tooling found in the checkout (`tools.sh`, `tests/Runtime/smoke-container.py IMAGE ENVFILE NETWORK [SOURCE_ROOT]`, `.docker/verify-openapi.php`). The reconstruction is marked as such; the first CI run on the real repository must be reviewed and treated as VERIFIED only after it passes there.

## 2. HS Code reference fixture excluded while its test remains

Evidence:

- `tests/Feature/HsCodeReferenceParityTest.php::test_frozen_catalog_fixture_matches_reference_registry_and_metadata` reads `tests/Fixtures/Integration/hs-code/catalog.json`.
- No `hs-code` directory exists under `tests/Fixtures/Integration/` (the generated tree only contains `FixtureController.php`, `metadata.php`, `localized-metadata.php` and route fixtures).
- The template snapshot excludes the fixture, so the generated repository cannot run that test against a real reference checkout even when `ULAXYS_HS_CODE_REFERENCE` is provided.

Disposition: the test now skips with the exact blocker when the fixture file is absent and keeps the strict comparison when it is present. The fixture contents are **not** invented; regenerating the frozen catalog requires a read-only `hs-code-service` checkout that is unavailable here. `export-catalog.php` remains the documented regeneration path.

## 3. Generated namespace changes break source-byte parity assertions

Evidence:

- The generator rewrites the root namespace per consumer service (`Ulaxys\\Identity` here; `composer.json` autoload maps `Ulaxys\\Identity\\` to `app/`).
- `HsCodeReferenceParityTest` hashed generator engine files byte-for-byte, including `app/Integration/**`, `bootstrap/app.php` and the `Generate*` commands, all of which embed `namespace Ulaxys\Identity\...` statements.
- Byte-identical hashing of namespace-bearing files can therefore never pass against a differently-named generated service, even when the engine logic is unchanged.

Disposition: byte parity now normalizes the `namespace Ulaxys\<Service>\` prefix before hashing on both sides. Engine logic parity remains strict; only the legitimately generated namespace token is ignored. Routes, `verify-openapi.php` and other namespace-free engine files continue to compare byte-for-byte after normalization (they contain no namespace statement).

## 4. Potential stale template-reference compatibility assertions

Evidence:

- `InventoryIdentityParityTest` and `InventoryMainGateParityTest` are opt-in (`ULAXYS_INVENTORY_REFERENCE`) and skip without a sibling Inventory checkout; they compare against live reference registries and would legitimately fail if the reference moves.
- `InventoryIdentityParityTest` deliberately asserts a reviewed reference defect (`PermissionSeedWriter.php` in Inventory lacks the `Database\Seeders\Support` namespace and contains `'role' => $data['role'] ?? null`).

Disposition: left unchanged. They are read-only, opt-in, and document their own skip behavior. No fixture was fabricated.

## 5. Symfony security advisories (November 25, 2026)

Verified against the public ecosystem during this unit (Symfony blog posts for 5.4.57/6.4.51/7.4.24/8.1.12 and the 8.1.12 release note dated November 25, 2026; CVE-2026-133743 for HttpKernel and related 8.1.12 advisories):

| Package               | Locked version | Patched boundary                        | Status   |
| --------------------- | -------------- | --------------------------------------- | -------- |
| `symfony/http-kernel` | v8.1.11        | >= 8.1.12 (CVE-2026-133743 and related) | AFFECTED |
| `symfony/mailer`      | v8.1.10        | >= 8.1.12                               | AFFECTED |
| `symfony/routing`     | v8.1.11        | >= 8.1.12                               | AFFECTED |

The patched-boundary claim matches the ecosystem evidence found. Full details, exact commands and the honest status of the lockfile update are documented in `docs/dependency-security.md`.

## 6. Files deliberately left untouched

Parity-hashed generator engine files (`app/Console/Commands/Generate*.php`, `app/Console/Commands/IntegrationCommand.php`, `app/Providers/IntegrationServiceProvider.php`, `app/Integration/**`, `routes/health.php`, `routes/openapi.php`, `.docker/verify-openapi.php`) are preserved byte-for-byte so the reference parity checks keep meaningful coverage. The Identity V2 work therefore wires middleware and routes without editing those files (middleware classes are referenced by fully-qualified class name in module route files; the module list lives in `routes/api.php`, which is not engine-hashed).
