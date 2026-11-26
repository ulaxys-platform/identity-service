# Start a new uLaxys PHP service

This service was created by the uLaxys PHP template generator. This checklist covers remaining configuration, not deployment. Use the existing PHP 8.5/Laravel 13 foundation; do not copy Inventory business models or expose the test-only ExampleLabel endpoint.

## 1. Create the repository and establish independent identity

- [ ] Create a new repository from this template; keep the committed `composer.lock` and existing CI.
- [ ] Change `composer.json` package `name` (for example `ulaxys/example-catalog`).
- [ ] Change `config/integration.php`: `service`, `gateway.mainGate.prefix`, `gateway.serviceGateway.prefix`. Keep separate prefixes for MainGate and Service Gateway; metadata does **not** install the Service Gateway.
- [ ] Change `.env.example` values `APP_NAME` and the service-specific `REDIS_PREFIX` (only used when Redis is selected).
- [ ] Choose independent database name/user and Redis namespace; set real values in an **untracked** `.env` or deployment secret store.
- [ ] Generate independent `APP_KEY` and `SERVICE_SIGNATURE` using deployment-appropriate secret management. Do not commit them. Signature proves trusted service transport, **not** a user's permission.

Check immutable generation origin and initial file hashes **before Composer, Pint, or business edits**:

```sh
python scripts/verify-generation.py inspect
python scripts/verify-generation.py verify
```

This baseline is unsigned and checks original files only; new business files are intentionally outside its inventory. Later modifications legitimately cause drift. Do not regenerate the manifest to hide changes.

Run the safe identity check **after customization**:

```sh
php scripts/check-new-service.php
```

An uncustomized template intentionally **fails** this check; this generated service should pass. It reads only `composer.json`, `config/integration.php` and `.env.example`, emits checklist labels (never secret values), never connects to a database, and never modifies files. It is not a readiness, security audit or deployment check. It can also inspect an isolated checkout via `--root=/path/to/project`.

## 2. Add the first real domain operation

- [ ] Read [architecture](architecture.md), the [executable ExampleLabel contract](domain-example.md) and the [integration generator documentation](integration-generators.md).
- [ ] Add real model and migrations under `app/` and `database/`; migrate **only** the intended disposable database during tests.
- [ ] Add one dedicated FormRequest for each operation and explicit Controller → Service → Repository Interface/implementation → Mapper → typed DTO → typed ResponseObject flow.
- [ ] Bind each repository interface in `app/Providers/AppServiceProvider.php`. Keep constructors side-effect free, since route introspection may resolve controllers.
- [ ] Add `routes/api/v1/<module>.php` with stable explicit names and register its filename in `routes/api.php`'s array. The actual middleware group is applied in `bootstrap/app.php`: `api`, `service.signature`, `json.body`, `lang`. Do **not** duplicate this group or add wildcard dispatch.
- [ ] Put the complete OpenAPI request/response, Signature scheme and operation ID attributes under `app/`. The scanner deliberately excludes `tests/Fixtures/`.
- [ ] Add exact named operation mappings, permission subject/actions and exposure decisions to `config/integration.php`. HTTP verbs alone never infer permissions.
- [ ] Implement unit, HTTP and transaction tests. When a test creates/drops PostgreSQL objects, call the existing destructive DB guard first and target only `service_test` or `service_testing`.

## 3. Verify the service before PR review

With PHP 8.5 + Composer installed (or the repository's development Docker image):

```sh
php scripts/check-new-service.php
composer validate --strict
composer audit --locked
composer check-platform-reqs
php vendor/bin/pint --test  # must pass directly; no preceding auto-formatting step
php vendor/bin/phpunit --testsuite Foundation
composer openapi
php artisan route:list
php artisan ulaxys:generate-integration --all --strict --dry-run
```

Run the separate `Database` PHPUnit suite **only** with `APP_ENV=testing`, disposable PostgreSQL named `service_test` or `service_testing`, and explicit `SERVICE_ALLOW_DESTRUCTIVE_TESTS=true`. The production-image build and networking smoke suite are also enforced by the existing GitHub Actions CI.

Review the generated artifacts with `php artisan ulaxys:generate-integration --all --strict` and `--check` in an isolated review workspace. Existing ownership rules refuse overwrites of generated output. Output alone does not prove native MainGate/Identity installation, grants, credential injection or real end-to-end connectivity. Test those in the actual integrated environment before release.

## 4. Review blockers

Do **not** approve a new service when its Composer name/service identifier or gateway prefixes are still template defaults, the source routes do not match OpenAPI operations, a business route lacks explicit integration metadata, a domain DTO leaks Eloquent fields, destructive tests target a non-test DB, or CI is red. Keep the example fixture test-only and do not move generator outputs into runtime route registration.

The preflight intentionally does not read credentials, test network access, inspect production database contents, or check external repository compatibility. Those are operator and integration acceptance tasks, not template initialization checks.
