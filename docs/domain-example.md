# Executable domain example (test fixture)

This is a **test-only** feature, not a shipped business API. There is no production route, migration, seeder, permission, or gateway package. All PHP classes are under [tests/Fixtures/DomainExample](../tests/Fixtures/DomainExample), autoloaded by the existing `Tests\\` PSR-4 dev namespace. The disposable PostgreSQL test alone mounts `/_example/labels` routes and creates/drops a uniquely named `example_labels_<random>` table per test after calling the existing destructive-database guard. This prevents fixture table collisions across concurrent test processes.

## Follow the actual layers

| Responsibility                                           | Executable reference                                                                                                                                                                             |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Domain model                                             | [ExampleLabel.php](../tests/Fixtures/DomainExample/ExampleLabel.php)                                                                                                                             |
| Strict GET/POST validation                               | [ListExampleLabelsRequest.php](../tests/Fixtures/DomainExample/ListExampleLabelsRequest.php), [CreateExampleLabelRequest.php](../tests/Fixtures/DomainExample/CreateExampleLabelRequest.php)     |
| Dedicated actions and operation-local OpenAPI attributes | [ExampleLabelController.php](../tests/Fixtures/DomainExample/ExampleLabelController.php)                                                                                                         |
| Business rules, transaction, typed results               | [ExampleLabelService.php](../tests/Fixtures/DomainExample/ExampleLabelService.php)                                                                                                               |
| Explicit persistence contract                            | [ExampleLabelRepositoryInterface.php](../tests/Fixtures/DomainExample/ExampleLabelRepositoryInterface.php)                                                                                       |
| Scoped PostgreSQL list/insert                            | [PostgresExampleLabelRepository.php](../tests/Fixtures/DomainExample/PostgresExampleLabelRepository.php)                                                                                         |
| Model-to-contract field allowlist                        | [ExampleLabelMapper.php](../tests/Fixtures/DomainExample/ExampleLabelMapper.php)                                                                                                                 |
| Typed public fields                                      | [ExampleLabelDto.php](../tests/Fixtures/DomainExample/ExampleLabelDto.php)                                                                                                                       |
| Operation-specific responses                             | [CreateExampleLabelResponse.php](../tests/Fixtures/DomainExample/CreateExampleLabelResponse.php), [ListExampleLabelsResponse.php](../tests/Fixtures/DomainExample/ListExampleLabelsResponse.php) |
| Executed checks                                          | [Unit](../tests/Unit/DomainExampleContractTest.php), [PostgreSQL HTTP + rollback](../tests/Database/DomainExampleIntegrationTest.php)                                                            |

HTTP → FormRequest → Controller → Service → Repository Interface → PostgreSQL Repository → Mapper → Typed DTO → Typed ResponseObject → HTTP. This is a responsibility flow, not an invitation to add a dispatch or handler layer.

The response keeps `size`, `status`, `message`, `items`, and list `pagination`. JSON translations remain JSON objects (including empty objects when valid). The underlying model includes `internal_note`, intentionally omitted by the mapper. Adding arbitrary model fields cannot silently change the response. The create operation checks domain uniqueness before persistence and executes inside a transaction. PostgreSQL's unique constraint is the final concurrency guard; a late SQLSTATE `23505` is caught **outside** the rolled-back transaction and returned as the canonical safe `409` error. A test forces the precheck to miss an existing code and checks the DB-enforced conflict. Other SQL errors are not hidden. The rollback test injects a failing repository implementation _after_ inserting, asserts the exact safe `500` envelope, and verifies that PostgreSQL committed nothing. Both fixture endpoints use `api`, `service.signature`, `json.body` and `lang`, matching production domain middleware; a test explicitly rejects missing/wrong Signature headers. These test-only credentials are not production secrets.

OpenAPI attributes in the fixture demonstrate controller-local ownership but are **not** mounted into the production OpenAPI document, by design. When promoting a real module, move it under `app/`, register explicit routes under `routes/api/v1/`, provide complete request/response schema attributes, bind the repository interface in `AppServiceProvider`, then run `composer openapi` and the existing strict integration generators. Do not mount the fixture on the shipped service.

## Run

```sh
php vendor/bin/phpunit tests/Unit/DomainExampleContractTest.php
# Only with disposable PostgreSQL named service_test or service_testing:
APP_ENV=testing SERVICE_ALLOW_DESTRUCTIVE_TESTS=true php vendor/bin/phpunit tests/Database/DomainExampleIntegrationTest.php
```

The existing test guard verifies the effective writer database, environment, and explicit opt-in before any table operation. Each test creates its own disposable table name and drops only that table in teardown. Never point this test at a development or production database.

## OpenAPI and MainGate/Identity handoff (P2)

The fixture includes a **real, test-scanned OpenAPI contract** but does not register the fixture in the production app:

- [ExampleLabelController](../tests/Fixtures/DomainExample/ExampleLabelController.php): operation-local GET/POST paths, operation IDs, Signature security, parameters, validation errors, `201` and `409` responses
- [DomainExampleOpenApi](../tests/Fixtures/DomainExample/DomainExampleOpenApi.php): isolated document context, exact create input and safe error schema
- [ExampleLabelDto](../tests/Fixtures/DomainExample/ExampleLabelDto.php), [Create response](../tests/Fixtures/DomainExample/CreateExampleLabelResponse.php), [List response](../tests/Fixtures/DomainExample/ListExampleLabelsResponse.php): explicit response schemas and nested typed item references
- [OpenAPI test](../tests/Unit/DomainExampleOpenApiTest.php): generates a **separate** OpenAPI document from the fixture and checks path, operationId, Signature and DTO/request/response references

The production Swagger scan still includes **only `app/`**. Never add `tests/` to production scanning or add fixture endpoints to `routes/api.php`. Production route/artefact parity is enforced by the unchanged `composer openapi` gate.

[Domain fixture integration metadata](../tests/Fixtures/DomainExample/integration.php) maps **exact Laravel route names** `fixture.labels.index` and `fixture.labels.store` to semantic `list`/`create` actions, `index`/`create` permissions and reviewed MainGate controller actions. The existing [IntegrationGeneratorTest](../tests/Feature/IntegrationGeneratorTest.php) mounts these two fixture routes only during the test, combines their metadata with the current integration fixture, runs the **real strict generator**, and checks MainGate verbs/destination, Identity subject/action output and idempotent `--check`. No request handlers are executed to discover contracts.

A real service must configure `config/integration.php` with its own unique service name, real MainGate/Service Gateway prefixes and explicit subject/actions; module and route names are examples, **not** reserved global naming contracts. Native Identity translations for tr/en/ar and runtime MainGate/Identity installation remain separate responsibilities. Generated packages are review artifacts, **not proof of deployed authorization or end-to-end gateway connectivity**.

```sh
php vendor/bin/phpunit tests/Unit/DomainExampleOpenApiTest.php
php vendor/bin/phpunit --filter test_real_domain_reference_routes_generate_explicit_gateway_and_identity_contracts
composer openapi
php artisan ulaxys:generate-integration --all --strict --dry-run
```

When creating a real service, move the pattern to `app/`, mount actual routes and metadata, regenerate OpenAPI from app classes and verify the generated integration bundle before installing it into MainGate/Identity. The empty template's own generator output is expected to be empty until business routes exist.
