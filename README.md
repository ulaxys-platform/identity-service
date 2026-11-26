# uLaxys Identity Service

A domain-neutral service foundation derived from `nrttns/inventory`. Runtime: **PHP 8.5, Laravel 13, PostgreSQL**, optional external Redis, PHPUnit 12, Pint and OpenAPI 3 through L5-Swagger/swagger-php. No Inventory tables, business operations, migrations or seed data are included.

This service was generated from the uLaxys PHP template. For customization, begin with the [new service setup checklist](docs/new-service.md) and run the read-only identity preflight after renaming template identifiers. See the executable [domain implementation example](docs/domain-example.md), [architecture](docs/architecture.md), [production runtime](docs/production-runtime.md).

## Unified code quality tools

The template and generated services include a portable `tools` command for
Laravel Pint, Prettier, and local test acceptance. Docker and Python 3.10+
are required; host PHP, Composer, and Node are not.

Windows PowerShell (the `tools.cmd` wrapper calls the shared runner):

```powershell
.\tools install
.\tools lint
.\tools test foundation
.\tools test database
.\tools test
.\tools verify
.\tools smoke
.\tools cache:status
.\tools clear:cache
.\tools clear:config
.\tools clear:routes
.\tools clear:events
.\tools clear:compiled
.\tools clear:views
.\tools clear:tests
.\tools clear:all
```

Linux/macOS: use `sh tools.sh` followed by the same command and suite names.
`smoke` validates the production image against disposable PostgreSQL;
No existing service database or Docker container is touched.
`lint` is read-only, `format` modifies checkout files, `test` runs tests
and `verify` adds Composer security checks, lint and OpenAPI. Database tests
use isolated disposable PostgreSQL, never an existing service database.
See [developer tooling](docs/developer-tooling.md) for supported suites,
isolation and CI-parity limitations.

## Local development

With PHP 8.5 and Composer 2 (curl, dom, mbstring, xml, xmlwriter, PDO and pdo_pgsql extensions):

```sh
composer install --no-interaction --prefer-dist
cp .env.example .env  # only if .env does not already exist
php artisan key:generate
# Set a locally generated SERVICE_SIGNATURE and external PostgreSQL settings in .env.
composer openapi
php artisan serve --host=127.0.0.1 --port=8000
```

Keep keys and local configuration untracked. Generate credentials locally (`php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`), never copy example production credentials. PHP's development server is for development only. Alternatively build `docker build --target development -t ulaxys-template:dev .` and run PHP/Composer commands in that image with this checkout mounted at `/app`. It includes Composer and the same PHP extensions as production.

`GET /healthCheck/apiStatus` proves application liveness without PostgreSQL. `GET /healthCheck/postgresStatus` executes a read-only PostgreSQL query and returns typed 503 on failure. `GET /openapi.json` requires the `Signature` header and serves the generated artifact without regeneration. There are deliberately no business endpoints.

## Environment

`.env.example` documents the runtime names. Configure `APP_NAME`, `APP_URL`, `APP_TIMEZONE`, `APP_KEY`, `SERVICE_SIGNATURE`, `DB_CONNECTION=pgsql` and either `DATABASE_URL` or `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Select PostgreSQL TLS policy with `DB_SSLMODE`; use verified TLS where required by deployment. No host addresses are embedded. The production image refuses missing key/signature/database identity, debug mode and non-stderr logging.

File cache and synchronous queues work without Redis. To use external Redis set `REDIS_HOST`/`REDIS_URL`, optional username/password, port, DB numbers and a service-unique prefix; choose `CACHE_DRIVER=redis` or `QUEUE_CONNECTION=redis`. Redis is a PHP extension, not a required always-running service. PostgreSQL/Redis run outside the application image. Never log request headers or credential values.

## Verification

```sh
composer validate --strict
composer check-platform-reqs
find app bootstrap config routes database tests public -name '*.php' -exec php -l {} \;
php vendor/bin/phpunit --testsuite Foundation
php vendor/bin/pint --test
composer openapi
```

For the separate Database suite, provision a **disposable** PostgreSQL database named exactly `service_test` or `service_testing`, export `APP_ENV=testing`, `SERVICE_ALLOW_DESTRUCTIVE_TESTS=true` and matching DB settings, then run `php vendor/bin/phpunit --testsuite Database`. Without explicit opt-in the integration test is skipped. The guard checks the actual writer connection identity, environment and driver before allowing destructive suites. The readiness probe does not mutate tables; native Identity writer tests create and remove only a uniquely named schema after the same safety guard. Future tests must call this guard before migrations/reset traits; never run `migrate:fresh` on development/production databases. Clear stale configuration with `php artisan config:clear` before test configuration changes.

## New services and modules

Follow the [new-service checklist](docs/new-service.md), including `php scripts/check-new-service.php` after customizing tracked identifiers. Create a repository from this template, rename Composer/application metadata, choose unique PostgreSQL/Redis resources, generate independent runtime secrets, update OpenAPI title/version and document your domain. Keep `composer.lock`, run frozen `composer install`, then run the checks above. Never copy Inventory business code or production credentials.

Add an explicit module file under `routes/api/v1/` and register its name in the small list in `routes/api.php`. Its enclosing route group already applies `api`, `service.signature`, `lang` in that order. Use explicit controller methods, stable slash-separated route names/operation IDs, dedicated FormRequests and controller-local OpenAPI attributes. Do not add catch-all dispatch routes. Start from the executable [test-only domain example](docs/domain-example.md), which demonstrates FormRequest, controller actions, service transactions, domain repository, mapper, typed DTO, typed responses and disposable PostgreSQL tests. Follow the responsibilities in [architecture](docs/architecture.md). After adding routes run `composer openapi`: release validation checks exact route/document parity, unique operation IDs, references, Signature security and artifact freshness.

`.github/workflows/ci.yml` runs these checks on every pull request and push to `main`: Composer validation and security audit, PHP syntax, Pint, PHPUnit Foundation and Database (disposable PostgreSQL), the OpenAPI gate, strict generator dry-run, the production image build, and `tests/Runtime/smoke-container.py` on an IPv4-only and a dual-stack network. Everything runs inside the repository's own pinned images. CI generates throwaway credentials per run and never publishes images, deploys or migrates. Reference parity tests skip in CI because they need sibling checkouts; run them locally.

## MainGate package and integration declarations

`php artisan ulaxys:generate-integration --all --strict` produces a native MainGate controller, service entry and modular route files with an installation README, alongside the existing gateway manifests and Identity permission declarations from registered routes plus explicit metadata. Independent gateway/permission commands, dry-run and stale-output checks are provided. Use `--gateway-format=json` for declaration-only output. Native MainGate installation is manual and requires real runtime verification; native Identity packages are also available from explicit tr/en/ar action descriptions. `--permission-format=auto` produces native + JSON when translations are complete and preserves legacy JSON-only behavior otherwise. Use `both`, `native` or `json` to select explicitly. Native Service Gateway adapters remain excluded. See [integration generators](docs/integration-generators.md) for configuration, security, output ownership and review instructions.

## Generation provenance

The original source commit and file checksums are recorded in `.ulaxys/generation.json`. Before installing dependencies or running Pint, inspect and verify the generated baseline with:

```sh
python scripts/verify-generation.py inspect
python scripts/verify-generation.py verify
```

These commands are read-only. Subsequent application edits may legitimately differ from the generation baseline.
