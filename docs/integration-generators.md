# Gateway, Identity and Deploy integration generators

These development tools read the booted Laravel route registry and explicit semantic metadata. They produce an **Inventory-style native PHP MainGate package** plus backward-compatible intermediate JSON declarations. With explicit translations they also produce a native Laravel Identity permission package; generation never executes its seeder. No HTTP routes, database records, grants, secrets or upstream repositories are changed.

## Source evidence and compatibility boundary

The implementation follows this service's PHP 8.5 / Laravel 13 bootstrap and the Inventory PHP route/module pattern, reviewed at `nrttns/inventory` commit `fef530b4f2303dc1fa258f92aa3eaf2f1e68b58f`. Inventory's `gateway/maingate` pack demonstrates explicit routes, original verbs/parameters, semantic permission mappings and removal of incoming Signature. Its own documentation says the real MainGate ServiceProxy and Identity applications were not supplied, and its tests use a recording double. It does not verify native Service Gateway APIs or Identity persistence/ID allocation. No original PHP MainGate, Service Gateway or PHP Identity checkout is present locally. No Doallon/.NET repositories were inspected or used.

The native MainGate renderer is verified against the actual Inventory package and isolated recording-double contracts. Its installation into the real MainGate application remains **unverified**. Native Service Gateway work remains excluded. Identity package generation is described below; real Identity runtime integration remains unverified. The generated compatibility reports set `deployReady: false` / `executableSeeder: false` and list the external verification work. Do not copy intermediate declarations into a runtime route loader and assume authorization/proxying is implemented. No end-to-end upstream integration is claimed.

## Commands

```sh
php artisan ulaxys:generate-gateway --strict
php artisan ulaxys:generate-permissions --strict
php artisan ulaxys:generate-integration --all --strict
php artisan ulaxys:generate-deploy --strict
php artisan ulaxys:generate-integration --deploy --strict
php artisan ulaxys:generate-integration --gateway --dry-run
php artisan ulaxys:generate-integration --permissions --check
php artisan ulaxys:generate-integration --all --output=review/service
```

The unified command defaults to all three targets (Gateway, Identity, Deploy); `--gateway`, `--permissions` and `--deploy` select targets and may be combined. `--all` cannot be combined with individual selections. Each independent command generates only its target. All commands support `--strict`, `--dry-run`, `--check` and `--output`. Gateway output defaults to `--gateway-format=both` (native PHP and existing JSON). `json` retains the old declaration-only mode, including metadata that cannot safely be rendered natively. `native` omits the two gateway route JSON manifests but retains the compatibility report. Permission selection is independent of this option.

- Normal generation validates the entire route/metadata contract before publishing selected output. Missing mappings, unknown metadata, unsupported routes and collisions always fail closed.
- `--strict` additionally rejects declared but unused permission actions. Otherwise these produce warnings and are omitted from the permission declaration.
- `--dry-run` prints relative planned file statuses without creating files, directories or locks.
- `--check` writes nothing and returns 1 for missing, stale or obsolete selected output; unchanged output returns 0. Combining it with dry-run is rejected.
- `--output` selects a bundle directory under the fixed allowlisted `generated/integration/` root. The default is `bundle`. Absolute paths, traversal, symlinks and overlapping/nested generated bundles are forbidden. Declaration destination filenames remain configurable JSON paths. Native PHP/README paths are fixed by the renderer using validated service/module/controller identifiers; metadata cannot select arbitrary executable paths.

In this cloud workspace PHP/Composer may run through the `ulaxys-template:dev` image with the checkout mounted at `/app`. No upstream infrastructure or production credentials are required for generation. The empty template contains no business routes, so its default output is intentionally empty catalogs plus infrastructure exclusions and compatibility reports. Adding a new service requires explicit metadata before generating any business declarations.

## Discovery and route safety

Artisan boots the actual application and resolves the HTTP kernel configuration (without handling a request) so middleware aliases/groups are checked against their effective classes. Only the three generator processes install a small `ObservedRouteCollection` before route boot; it keeps registration history while preserving Laravel's normal collection/matching behavior. This is necessary because Laravel overwrites exact duplicate registrations before ordinary `route:list` introspection can see them. HTTP requests and other Artisan commands keep the ordinary Laravel collection. Cached routes are refused: run `php artisan route:clear` before generation so original registration order and history are available.

The collector never invokes route handler methods/closures or runs middleware. Laravel's controller-middleware introspection may instantiate controllers, so constructors must remain free of I/O and side effects, as in the template's transport architecture. It records methods, root-relative URI, name, action reference, middleware and nested parameter names/optionality/constraints. Module and semantic operation identity come from metadata, never directory/name/verb guesses. `routes/api.php` remains an explicit lightweight module orchestrator; `routes/api/v1` is a source organization, not an automatically added HTTP prefix.

Implicit HEAD paired with GET is omitted from operation counts; a standalone explicit HEAD is separate, including an explicit override of GET's automatic alias. Source methods, parameter spelling, optional markers and constraints remain in manifests. Routing order is preserved rather than alphabetically sorting URI patterns. Native adapters must preserve Laravel GET/HEAD behavior when converting the manifests.

Only exact GET/HEAD health and document identities (URI + route name + known action + middleware boundary) are infrastructure exclusions. A business route cannot hide behind a health prefix or an `openapi` name. All other routes need the existing `api` boundary and supported `service.signature`, `json.body`, `lang` middleware; excluded middleware, unknown aliases/resolved middleware are rejected instead of guessing its authentication semantics. Domain-scoped routes, embedded/encoded or dot URI segments, non-trailing optional parameters, fallback routes, route defaults, multi-segment catch-alls and unsupported methods are rejected with actionable errors. Closures are introspected normally and require the same explicit metadata and protected boundary as controller routes. Native proxy actions do not execute the source handler.

Duplicate method/path registrations and duplicate route names are rejected. Potential earlier parameter routes shadowing later literal routes, or ambiguous parameter templates, are rejected conservatively, including optional parameters. Specific paths must precede parameter routes. Only the reviewed single-segment constraint list in RouteDiscovery is accepted (including Laravel 13's alpha, alphanumeric, number, ULID and UUID helpers and the listed safe numeric/slug forms). Custom PCRE is rejected until reviewed and tested; finite sample matching cannot prove a pattern slash-free. The overlap check does not try to prove arbitrary regex constraints disjoint; reorder/split ambiguous routes rather than relying on an unverified regex solver. No source files are parsed with regular expressions to discover routes.

## Version 1 metadata

Edit trusted Laravel configuration in `config/integration.php`. It is application configuration, not an untrusted PHP upload or a duplicate route specification. The schema has exactly `schemaVersion`, `service`, `gateway`, `permissionsFile`, and a `modules` list. Unknown keys are errors. Keep runtime credentials/URLs out of metadata; generation never reads or exports APP_KEY, SERVICE_SIGNATURE, database settings or arbitrary process environment.

Example for a new synthetic module (create the real module's registered routes separately):

```php
return [
    'schemaVersion' => 1,
    'service' => 'warehouse',
    'gateway' => [
        'mainGate' => ['prefix' => 'services/warehouse', 'file' => 'gateway/maingate.routes.json'],
        'serviceGateway' => ['prefix' => 'warehouse', 'file' => 'gateway/service-gateway.routes.json'],
    ],
    'permissionsFile' => 'identity/permissions.json',
    'modules' => [[
        'id' => 'records',
        'permissions' => [
            'subject' => 'warehouse-records',
            'description' => 'permission.warehouse-records',
            'actions' => [
                ['action' => 'index', 'description' => 'permission.warehouse-records.index', 'module' => 'warehouse'],
                ['action' => 'create', 'description' => 'permission.warehouse-records.create', 'module' => 'warehouse'],
            ],
        ],
        'operations' => [
            'records.index' => ['operation' => 'list', 'gatewayAction' => 'listRecords', 'permission' => 'index', 'expose' => true],
            'records.search' => ['operation' => 'search', 'permission' => 'index', 'expose' => true],
            'records.store' => ['operation' => 'create', 'permission' => 'create', 'expose' => true],
        ],
    ]],
];
```

Operation keys are **full exact Laravel route names**, not guessed controller methods. `operation` is the explicit semantic identity, unique within its module. The example can map a POST search route to the `index` permission: HTTP verbs never determine grants. A route handling PUT and PATCH may share one operation/mapping. Multiple operations may explicitly share one action; catalog declarations of the same subject/action cannot be duplicated. Subject identities must be unique across modules; services should namespace them deliberately to avoid external collisions.

Every operation requires a boolean `expose` and exactly one declared `permission` action or nonempty documented `exempt` reason. A module containing only exempt operations may use `permissions => null`. Public business operations must be explicitly exempt **and** `expose => false`; generation cannot expose additional public endpoints. Native generation rejects exposed protected exemptions: there is no reviewed permission-free MainGate representation. They may remain unexposed, or use JSON-only generation for review. Unexposed operations are recorded with an exclusion reason. An unmapped protected operation, stale route mapping, nonexistent action or conflicting mapping always fails, even without strict mode.

Subjects/actions/operation/service/module identifiers are bounded alphanumeric names allowing `_` and `-`; names are case-sensitive. Descriptions may be plain text or localization keys. Action `module` is explicitly provided for the existing Identity model and is not inferred from the route's module identity. No numeric IDs, ID allocation behavior, roles, tenant/guard schema, helper methods or default grants are assumed.

## Output contracts

The default bundle contains:

```text
generated/integration/bundle/
  gateway/maingate/controllers/ServiceController.php
  gateway/maingate/routes/service.php
  gateway/maingate/routes/service/<module>.php
  gateway/maingate/README.md
  gateway/maingate.routes.json
  gateway/service-gateway.routes.json
  gateway/compatibility.json
  identity/permissions.json
  identity/compatibility.json
  .ulaxys-generated.json
```

Each declaration has `generatedBy: ulaxys-integration/v1`, `schemaVersion: 1` and an explicit `kind`. Gateway manifests contain `target`, `service`, security requirements, ordered route records with source URI and destination path, explicit permission/exemption, action reference and parameter metadata. `mainGate` and `serviceGateway` use independently configured prefixes; they do not imply a verified native routing topology. No deployment URL, credentials or generic catch-all are emitted. The native controller and modular routes are described below.

The backward-compatible intermediate Identity envelope contains `permissions`, sorted by subject; each subject has `subject`, `description` and sorted `actions`, each with exactly `action`, `description`, `module`. This JSON is a validated review declaration, **not** an executable seeder/migration. The separate native package delegates new IDs to the installed database. Compatibility reports retain legacy JSON flags and add nativeIdentity.executableSeeder=true/runtimeVerified=false when native output is selected. Compatibility files list the unresolved native contracts. There are no timestamps/random identifiers in output bytes, so identical registrations and metadata generate identical bytes.

The ownership ledger tracks file hashes and selected target groups. Existing unowned destinations or edited generated files are refused; there is no force overwrite. Copy human edits out, review them, and remove/reconcile the affected bundle deliberately before regenerating. Other non-target human files are preserved. Independent commands preserve the other target's files; renaming a configured generated destination removes only obsolete generator-owned files for the selected group. No unrelated catalog records, runtime routes, grants or upstream files are deleted.

Generation uses a cooperative lock, stages the complete bundle in a private directory on the same filesystem and publishes using directory renames. Errors before publication leave original output untouched; publication failures restore the prior directory, while failed rollback retains a `.backup-*` directory with an explicit recovery error. Generated files are never exposed half-written. A process crash between directory renames can temporarily leave the bundle absent; inspect retained `.backup-*`/`.stage-*` directories under the allowed root and recover under exclusive access before retrying. The next write refuses leftover transaction directories. Filesystem/OS access controls must prevent untrusted processes from modifying the generator's output directories; advisory locking is not an OS security boundary against hostile processes sharing the same UID. Generated bundles are ignored by Git and excluded from Docker build input; review/export them explicitly.

## Adding modules and native adapters

1. Add a domain module through the existing `routes/api.php` list, using named routes, explicit controllers/FormRequests and the established API/Signature boundaries. Keep controller-local OpenAPI attributes and run `composer openapi`.
2. Add the module's semantic metadata. HTTP methods/paths belong exclusively to registered routes. New registered operations without metadata block generation.
3. Run dry-run, strict generation, then check. Review both target manifests, excluded operations and permission descriptions. Do not treat an empty template catalog as proof of a future service integration.
4. Manually install the native MainGate package using its generated README. Register the service destination in the existing ServiceProxy configuration and install permissions separately. Confirm auth, permission middleware and trusted credential injection in the real application. Never register generated routes in this source service.
5. Provide explicit tr/en/ar action translations and generate the native Identity package. Follow its manual installation README, review the shared writer and verify the actual Identity schema, defaults, uniqueness and cache workflow before running it. No source service DB seeding or role grants are generated.

CI (`.github/workflows/ci.yml`) runs the checks below on pull requests; locally, run `composer validate --strict`, `composer check-platform-reqs`, PHPUnit, Pint, `composer openapi` and a production Docker build before delivery. Generator tests use synthetic modules under `tests/Fixtures/Integration` plus a frozen, data-only HS Code catalog (see below); they never copy business logic or register those routes in the service runtime.

## PR #2 verification evidence

Validated on PHP 8.5.11 / Laravel 13.37.0 with unchanged Composer manifest/lockfile:

- Full PHPUnit suite: **90 tests, 340 assertions**, zero failures/errors/skips. This includes **47 generator cases** using synthetic modules, real Laravel discovery, duplicate/shadow detection, middleware resolution/exclusions, permissions, deterministic output, dry-run/check/idempotency, traversal/symlink defense, ownership checks, staged-write failure and publication rollback. The existing disposable PostgreSQL readiness and OpenAPI regressions also passed.
- `composer validate --strict`, `composer check-platform-reqs`, Pint and PHP syntax checks passed.
- OpenAPI generation and release validation passed for the unchanged two health operations. No generator or fixture HTTP routes were added to the runtime.
- Each independent Artisan generator ran in a fresh CLI process; unified `--all --strict --check` found all five generated declarations/reports unchanged.
- Production Docker build passed, including frozen production dependency installation, strict PSR autoload/platform checks and build-time OpenAPI verification. Final image: `sha256:586d95c0f69aa9f801dc991e4b6c48247e55bb33ccae343e097731e737560741`.
- The actual-image smoke harness passed: non-root runtime/dependency isolation, Signature, health/readiness/outage, OpenAPI delivery, logging/redaction, fatal-child handling and graceful shutdown.

These are local template/tooling and production-container checks. **No actual PHP MainGate, Service Gateway or Identity application was exercised; real MainGate/Identity installation and native Service Gateway work remain external integration dependencies.** Nothing was deployed, published to a container registry or merged automatically.

## Native MainGate metadata and behavior

`gateway.mainGate.prefix` is used exactly as configured; source directory names never imply api/v1. Optional `gateway.mainGate.destination` overrides the ServiceProxy destination key (default: service); optional `gateway.mainGate.controller` overrides the PHP class name (default: Studly service + Controller). Controller names must be ASCII PHP identifiers ending in Controller and cannot replace the base Controller.

Each operation may set `gatewayAction => 'listRecords'` to retain a reviewed action identity. Otherwise the stable action is `camel(module)_camel(operation)`, for example `records_search`, with route name `<service>.<action>`. Adding unrelated routes does not rename actions. Case-insensitive action collisions, inherited Controller methods and reserved transport helper names are refused; supply distinct explicit identifiers. PHP syntax is checked before publication.

The entry applies the existing MainGate `auth` middleware once and requires one file per exposed module. Each route has exactly `permission:<action>-<subject>` from the declared catalog. No verbs, parents or wildcard grants determine permissions. Native permission components permit ASCII letters/digits/hyphens; case is retained for existing declarations. Underscores and ambiguous concatenations are refused rather than silently renamed. Use namespaced kebab-case subjects such as `warehouse-records` for new services. POST reads explicitly map to index; reorder/move writes explicitly map to edit.

Route methods, parameter names and reviewed constraints come from Laravel. GET includes Laravel's HEAD alias; explicit HEAD and OPTIONS use Route::match with exactly their declared methods. Multi-method routes retain their method list. An explicit HEAD route overlapping a GET alias is refused because Laravel uncached and compiled matching can choose different actions. TRACE and optional parameters are unsupported by native generation and fail before any publication. JSON-only mode keeps its existing broader declaration contract.

Module files retain source registration order internally. Module inclusion order is stable by first source appearance, adjusted only for precedence dependencies between potentially matching routes (including GET/HEAD aliases). If grouping would create a dependency cycle, generation fails with a regrouping diagnostic; it never silently changes matching behavior. Independent non-overlapping routes may appear in a different global order than Inventory's manually grouped package.

Every generated action forwards the original Laravel Request and returns the exact ServiceProxy result. Bodies, raw query, Language and original HTTP method remain untouched. Incoming Signature is removed from both bags; the trusted existing ServiceProxy must inject its runtime-configured internal Signature and enforce MainGate's identity-header policy. The controller alone is not the entire security boundary. Parameter values accept int|string without integer coercion, reject empty/dot-only/separator/control segments, and use rawurlencode exactly once. Literal percent/reserved/UTF-8 data and leading-zero identifiers are retained. Route constraints apply at the gateway router.

Native PHP and README use the same ledger, same-filesystem stage/rollback, symlink checks, ownership protections and dry-run/check flow as JSON. Switching formats removes only safely owned obsolete gateway files; independent permission declarations and human siblings survive. Edited owned native files block regeneration. Outputs stay ignored and excluded from production images.

## Repeatable Inventory reference verification

```sh
# Separate read-only checkout, not a runtime dependency of this template:
ULAXYS_INVENTORY_REFERENCE=/path/to/inventory vendor/bin/phpunit --filter InventoryMainGateParityTest
```

The test boots Inventory's actual source routes in an isolated process using this template's installed PHP/Laravel dependencies, redirecting all bootstrap cache/storage writes to temporary directories. It compares the real manifest, native route files and generated package semantically: operations, source actions, methods/paths, modules, parameters, constraints, permission middleware, stable controller actions, exclusions and significant matching order. Counts derive from the reference, not historical constants. The reference is never modified. Without the explicit checkout path this test is reported skipped; ordinary template tests need no other repository.

Generated PHP is syntax checked and exercised with an isolated recording ServiceProxy double, including unchanged request/response forwarding, Signature removal, hostile/literal segments and compiled route matching. Auth/permission doubles test placement and execution order, not the real MainGate authorization implementation. The real MainGate runtime is unavailable, so no end-to-end integration claim is made. The Inventory source bootstrap uses template dependencies rather than installing/changing Inventory's lockfile; full Inventory application tests are outside this parity check.

## Native Identity permission package (PR #4)

The authoritative Identity reference is `nrttns/inventory@2bb349fa3de082cad589b472a0d8464d6469e998`, under `identity/database/seeders/`. Its catalog has the reviewed data-wrapper shape and explicit Turkish, English and Arabic translations. The actual reference writer is global while its seeder imports `Database\Seeders\Support\PermissionSeedWriter`; it also assigns absent role/model fields to null. Generated code fixes both issues without changing Inventory.

```sh
php artisan ulaxys:generate-permissions --permission-format=both --strict
php artisan ulaxys:generate-permissions --permission-format=native --strict
php artisan ulaxys:generate-permissions --permission-format=json
php artisan ulaxys:generate-permissions --dry-run
php artisan ulaxys:generate-permissions --check
php artisan ulaxys:generate-integration --all --strict
```

`--permission-format=auto` is the backward-compatible default: complete translations produce native files plus the existing JSON declarations; legacy string descriptions/localization keys produce JSON only with a warning. An empty catalog can produce an empty native seeder. `both`/`native` explicitly require all translations, even without strict mode. `native` omits only identity/permissions.json; json removes only safely owned obsolete native permission files. Gateway output and unrelated human files remain independent. Strict mode also retains unused-action checks. No MainGate/Identity checkout or database is needed to generate.

Keep subject description as its existing text/localization key. Extend each **action** description with explicit translations, for example (illustrative text, not production defaults):

```php
'permissions' => [
    'subject' => 'warehouse-records',
    'description' => 'permission.warehouse-records',
    'actions' => [[
        'action' => 'index',
        'module' => 'warehouse',
        'description' => [
            'tr' => 'Kayıt Listeleme İzni',
            'en' => 'Record Listing Permission',
            'ar' => 'إذن عرض قائمة السجلات',
        ],
    ]],
],
```

Legacy strings are never translated, repeated into all languages or resolved as locale labels. Language maps must contain exactly tr/en/ar, nonempty bounded UTF-8 text without controls; localization-key-shaped values such as permission.records.index or labels.records.index are rejected. Translation ordering is canonical. Operators supply and review real service translations. Partial maps, unknown fields and invalid native identifiers fail before publication. Native module/subject/action identifiers support ASCII letters, digits and hyphens, retaining case. Underscores remain supported only in legacy JSON identifiers where already allowed. No permission is inferred from an HTTP verb.

For the configured warehouse service, the complete Identity portion is:

```text
identity/
  database/seeders/
    Permissions/WarehousePermissionSeeder.php
    Support/PermissionSeedWriter.php
    Support/permissions-warehouse.php
    Support/permissions-warehouse-keys.json
  README.md
  permissions.json
  compatibility.json
```

There is deliberately no generated DatabaseSeeder replacement. PHP catalog entries contain only a `data` object with action, subject, declared Identity module and multilingual description. Keys JSON follows Inventory's exact schema_version/service/permissions{name,action,subject} envelope (without a second source of permission definitions). Catalog and manifest sort by canonical action-subject name. Native files and raw key JSON use the existing ownership ledger and atomic writer. No generated catalog is evaluated during generation; the native writer is a reviewed static template, not arbitrary user-supplied code. Paths are narrowly allowlisted and PHP is syntax parsed before publication. Outputs remain ignored and excluded from Docker build context.

### Identity and middleware contract

Identity lookup is **module + subject + action**. MainGate names remain exactly **action-subject**. Each mapped reference must resolve once to the same catalog identity; duplicate identities, concatenation collisions, cross-module ambiguity, missing declarations and exposed exemptions fail. No wildcard, OR grants, IDs, parent permissions or role fallbacks are added. PR #3's native MainGate renderer and native output behavior are unchanged.

### Shared writer policy

`Database\Seeders\Support\PermissionSeedWriter::seed(string $catalog): void` validates the complete trusted installed PHP catalog before any DB query, then uses a transaction. It reads/locks existing permission identities and descriptions in one query to detect canonical collisions even across different subjects/modules, inserts missing identities and updates **description only**, skipping unchanged descriptions. New IDs come from the database. There are no deletes, truncates, role queries/attachments, grants or user writes. Existing ambiguous records fail rather than merging IDs/grants. Invalid catalogs fail before queries; later DB failures roll back prior writes.

Absent model, role, active and other operational metadata remain untouched. Explicit optional metadata is intentionally **unsupported/rejected**, because the supplied catalog does not establish its complete schema or a reviewed role-resolution/update policy. Manage it through existing Identity operational workflows. The isolated fixture models verified permission columns plus sentinel grant rows for preservation tests; it is not a production migration or a claim about Identity's grant schema.

The supplied package does not establish actual Identity insert defaults, unique constraints, concurrency behavior, grant schema or cache APIs. Before use, verify required defaults and uniqueness in Identity. Run seeders exclusively through the existing maintenance workflow: row locks cannot serialize insertion of an absent identity without a verified database uniqueness constraint. No new migrations, locking framework or assumed uniqueness contract is generated. Refresh caches only through Identity's actual supported mechanism after successful reviewed installation.

### Manual installation

Copy service-specific seeder and catalog to their matching database/seeders subdirectories; keep keys JSON for review. **Review the shared writer separately**: do not overwrite an existing class or add a second conflicting one. Verify/reconcile its namespace, seed API and description-only policy. Ensure Identity's Composer maps Database\Seeders\ to database/seeders/ and refresh its autoload metadata. Register the service class exactly once in the existing DatabaseSeeder:

```php
$this->call([
    \Database\Seeders\Permissions\WarehousePermissionSeeder::class,
]);
```

Run through Identity's existing reviewed Artisan seeding workflow against a verified staging database first. Record and compare IDs, roles, grants, model/active fields and unrelated records before/after/repeat. Inspect translations and exact MainGate key parity; verify real allowed/denied requests and cache refresh. Generation does not install, run or register any seeder in this template. Use the generated service-specific README for exact names/commands and regeneration instructions.

### Identity reference and database verification

`ULAXYS_INVENTORY_REFERENCE=/path/to/read-only/inventory vendor/bin/phpunit --filter InventoryIdentityParityTest` reads the actual reference PHP catalog, keys manifest, MainGate manifest and registered source routes; it runs the existing mapper and both native renderers, compares exact catalog/key data and checks every generated MainGate middleware key. Counts come from current files, not a permanent 78-permission assertion. This opt-in test explicitly skips without a checkout.

Native writer DB tests use the existing destructive guard, a disposable PostgreSQL database and a unique temporary schema. They cover first/repeated runs, no-op repeat queries, Unicode description updates, stable IDs, retained role/model/active fields and grant sentinels, unrelated rows, pre-query validation, duplicate/canonical ambiguity, rollback and multiple installed service seeders sharing one writer. The actual Identity application is unavailable; these generated-package tests do not prove its schema, cache or runtime integration.

## Generator ownership, versioning and propagation

The template owns one generator engine for every PHP service. Services own only their metadata, routes and controllers. Do not fork or patch the engine in a service: change it here, then propagate it.

| Owner                                            | Files                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Template (byte-identical in every service)       | `app/Integration/**` (including `Stubs/PermissionSeedWriter.php.stub`), `app/Console/Commands/{IntegrationCommand,GenerateIntegration,GenerateGateway,GeneratePermissions}.php`, `app/Providers/IntegrationServiceProvider.php`, `bootstrap/app.php`, `routes/{health,openapi}.php`, `.docker/verify-openapi.php`                                                        |
| Template test suites, kept unchanged by services | `tests/TestCase.php`, `tests/Feature/{IntegrationGenerator,NativeMainGateGenerator,NativeIdentityGenerator,InventoryMainGateParity,InventoryIdentityParity,HsCodeCatalogRegression,HsCodeReferenceParity,OpenApiRouteScope}Test.php`, `tests/Database/NativeIdentitySeederTest.php`, `tests/Support/{Identity,Integration,MainGate}/**`, `tests/Fixtures/Integration/**` |
| Service                                          | `config/integration.php` and any files it merges, `routes/api.php`, `routes/api/v1/*.php`, controllers, service-specific contract tests                                                                                                                                                                                                                                  |

Template suites isolate their synthetic fixture from consumer routes under `api/v1/`. They derive route and OpenAPI operation counts from a fresh `route:list`. A service therefore keeps them unchanged when it adds business routes.

**Versions.** These identifiers version the emitted contracts:

- metadata `schemaVersion: 1`;
- declaration `schemaVersion: 1` and `kind`;
- ledger owner `ulaxys-integration/v1`;
- Identity writer API v1 (`nativeIdentity.contractVersion: 1`, description-only updates).

For unchanged routes and metadata, the generator must emit the same bytes. Any engine change that alters emitted bytes is a contract change. It must update `tests/Fixtures/Integration/hs-code/expected.json` in the same PR and explain each changed file. An incompatible change to a declaration, permission identity or writer API must bump the relevant version and document the consumer migration. Permission identities (`<action>-<subject>`, module, subject, action) must never be renamed implicitly, because Identity role grants reference them.

**Propagation to a service** (a separate, reviewable PR per service):

1. Copy the template-owned files above from a recorded template commit; never merge service-specific metadata back into the template.
2. Run the full PHPUnit suite, `composer openapi` and Pint in the service.
3. Run `php artisan ulaxys:generate-integration --all --strict --check` against the service's previous bundle. The expected result is `unchanged`. Explain any stale file before regenerating.
4. Record the template commit in the service PR. Do not install packages, run seeders or grant permissions as part of propagation.

## HS Code catalog regression and reference parity

`tests/Fixtures/Integration/hs-code/catalog.json` is the integration catalog of `hs-code-service@27bda520bf37250d73f3b7b1bdeb63f8b3498746`, frozen as test data: 18 modules, 98 Signature-protected operations, 18 subjects, 62 permissions, numeric and UUID constraints, and modules that are non-contiguous in route order. It contains only route records (methods, URI, name, action string, middleware, constraints) and the resolved integration metadata; no HS code or configuration is loaded by the template runtime. `expected.json` pins the SHA-256 of every file the service's own generator produced from it, including the ledger.

`HsCodeCatalogRegressionTest` replays the catalog through this template's generator in fresh Artisan processes (`tests/Support/Integration/generate-catalog.php`). It covers:

- byte identity with the pinned outputs, unchanged regeneration and a passing `--check`;
- the native MainGate package: one route per operation with prefix, verbs, constraints and `permission:<action>-<subject>`, Signature removal and forwarding to `hs-code`, plus the recording-double contract (401/403/forward, compiled order, HEAD, hostile segments);
- the native Identity catalog: non-null module/subject/action, exact tr/en/ar descriptions, unique sorted keys equal to the MainGate middleware keys, and acceptance by the shared writer's validation. No seeder or database is executed;
- drift: a translation change marks only the two description-bearing files stale and keeps keys and the MainGate package byte-identical; a removed module is reported `obsolete`; `--check` never writes;
- fail-closed validation: an unused action under `--strict`, null module, empty action, duplicate subject, missing translation, unmapped route and unsigned exposed route.

With a read-only checkout, `HsCodeReferenceParityTest` also verifies that the service runs this template's engine byte-for-byte and that the frozen catalog equals its live registry and metadata:

```sh
ULAXYS_HS_CODE_REFERENCE=/path/to/hs-code-service vendor/bin/phpunit --filter HsCodeReferenceParityTest
```

When the reference moves, refresh both fixture files from one service commit:

1. Run `php tests/Support/Integration/export-catalog.php /path/to/hs-code-service > tests/Fixtures/Integration/hs-code/catalog.json` from the template.
2. Run `php artisan ulaxys:generate-integration --all --strict` in the service and record each bundle file's SHA-256 and the commit in `expected.json`.

A digest change without a matching catalog change means the engine changed its output contract (see above).

## Consumer contract evidence (Issue #5)

| Acceptance criterion                                                               | Evidence                                                                                                                                                                  |
| ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| New services generate correct MainGate and Identity packages without manual fixups | Template suites pass unchanged in a fresh service with business routes, and in hs-code-service; HS catalog regression                                                     |
| `--check` passes on unchanged input and fails on stale output                      | HS catalog regression; native generator tests                                                                                                                             |
| Route, auth, Signature and permission parity with real consumers                   | `InventoryMainGateParityTest`, `InventoryIdentityParityTest` (actual Inventory checkout); HS catalog byte parity and contract harness                                     |
| No duplicate permissions; role assignments unchanged                               | Generator collision checks; `NativeIdentitySeederTest` (disposable PostgreSQL fixture: repeat runs, stable IDs, preserved role/grant sentinels, cross-subject collisions) |
| No runtime, schema or data mutation during generation or audit                     | Fresh-process generation with an unreachable DB host; `--dry-run`/`--check` write nothing; installation stays manual                                                      |

The uLaxys web client (`ulaxys-platform/uLaxys`) commits Identity's OpenAPI contract. `PermissionDto` has `subject` and `description`, `PermissionActionDto` has `id`, `action`, `description` and `module`, and `AbilityDto` has `subject` and `action`. The client authorizes with `(subject, action)` pairs such as `inventory-taxes/index`. Generated catalogs supply exactly those identity fields, and the DB assigns IDs. The API exposes `description` as one string, while the generated seeder stores a JSON tr/en/ar map, as Inventory's reference catalog does. How Identity localizes that map on read is not verifiable here.

**Not available for verification:**

- the PHP MainGate application (ServiceProxy, `auth` and `permission` middleware);
- the PHP Identity application (schema, unique indexes, permission cache);
- a native Service Gateway.

None of these is in any repository this workspace can access. Recording doubles and the isolated PostgreSQL fixture establish generator semantics, not end-to-end integration.

## P5 — Deploy integration package (manual transfer)

The reviewed `ulaxys-platform/ulaxys-deploy` contract at commit `4d5f3996bc4ea545277665b24c534b28fdcf58b8` uses `service.json`, `compose/services.local.yaml`, independent `compose/production/services/<name>.yaml`, `config/deployment.contract.json`, environment examples, private production target placement, resource inventory, and strictly reviewed Operator CLI V2 capabilities. Adding only a Compose file is insufficient.

`--deploy` or `ulaxys:generate-deploy` produces a review-only package under the same owned bundle and locked, staged output writer. The package includes JSON-compatible Compose fragments, safe runtime/image examples, names-only secret requirements, explicit target-path patch instructions, a compatibility status and manual installation README. `--all` now includes Deploy; `--check` verifies the combined bundle. A base template with placeholder identity emits `templatePlaceholder: true` and is **not installable**.

**Manual installation remains mandatory.** A reviewer must merge catalog/Compose/contract/image and environment entries into the correct deploy repository paths without overwriting unrelated services; select a free local port, independently provision database/ACL, manually update all three `config/topologies/` examples when enabling production, and choose reviewed production host/ports/digest. Explicit `REPLACE_ME` values must be replaced through operator review before runtime validation. Operator V2 capability proposals are **not automatically installable**, since its registry enforces source-reviewed adapters and allowlists. No production target, version, database, privilege, secret, persistent volume or runtime readiness is inferred.

After manual transfer, run the **deploy repository's** `python3 scripts/validate_repository.py`, its unit tests and `invoke.ps1 local validate <service>` (or `invoke.sh`), followed by actual local Docker and gateway/database acceptance. Static validation does not prove real deployment, MainGate/Identity installation or permission assignment. Do not copy the generated `patches/*.json` as replacements for complete central catalog files.
