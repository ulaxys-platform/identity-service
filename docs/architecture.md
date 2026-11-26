# Architecture

Inherited from Inventory's actual implementation: **Controller → Service → Repository → Mapper → Typed DTO → Typed ResponseObject**. Controllers handle HTTP and dedicated FormRequest input. Services orchestrate operations and construct typed response contracts. Repository interfaces have explicit domain methods; concrete repositories own Eloquent queries and writes. Providers bind interfaces. Model-specific mapper traits live in `app/Presenters/Mapper`; they copy scalar/array values into typed DTO properties. DTOs never retain models. Do not put database queries into controllers, DTOs or response objects.

The production template has no domain models or migrations. An executable [test-only domain fixture](domain-example.md) demonstrates the complete domain flow using a disposable PostgreSQL table, a real HTTP controller and operation-specific typed response classes. The fixture is not included in production routes, OpenAPI generation, deployment or integration packages. Its Health controller delegates to HealthService and an explicitly bound HealthRepositoryInterface for the PostgreSQL probe. Liveness bypasses persistence entirely. A mapper/DTO for an empty infrastructure probe would add no useful contract; those layers are available for real domain modules through BaseDto and HttpSuccessResponseObjectOfContract.

## Layout

- `bootstrap/`: Laravel 13 application, middleware aliases, route boundaries and providers.
- `app/Http/`: transport controllers, language/signature middleware, strict shared FormRequest.
- `app/Services/`: orchestration; `app/Repositories/RepositoryInterface` and `Repository`: persistence contracts/implementations.
- `app/Presenters/Mapper/`: future model-to-value mapping traits; `app/Dto/`: typed values.
- `app/Transformers/`: response serialization; `app/Exceptions/`: safe JSON errors; `app/Logging/`: secret redaction.
- `routes/api.php`: explicit versioned module orchestrator. `health.php` is public infrastructure; `openapi.php` is Signature protected.
- `config/`: runtime-only configuration. `database/`: intentionally empty domain foundation.
- `.docker/`: runtime guards, release OpenAPI gate, FPM/Nginx/Supervisor configuration.
- `tests/`: unit/HTTP contracts, disposable PostgreSQL probe and database safety guard.

## Typed contracts

Success retains Inventory's `size`, `status`, `message`, and operation-specific `items` contract; empty infrastructure responses omit items. Errors retain `status`, `code`, `message`; request validation uses HTTP 400, not Laravel's 422 envelope. Unexpected failures always return safe 500 text, even in debug mode. PostgreSQL conflicts/constraint failures are mapped without SQL, bindings or stack traces.

A new module declares `final class ThingDto extends BaseDto { public string $id; public string $name; }` and `final class GetThingResponse extends HttpSuccessResponseObjectOfContract { public ThingDto $items; }`. Its mapper copies model fields into ThingDto, service assigns the DTO and success metadata, and controller serializes `toArray()` with `getStatus()`. List response subclasses declare `/** @var list<ThingDto> */ public array $items;`. Tests must verify model-free nested serialization and actual domain operations. Do not use untyped Eloquent serialization as an API contract.

BaseDto and ResponseObject/error classes are retained; filter/listing mechanics are excluded because they encode Inventory behavior. Inventory BaseRepository is deliberately excluded: advisory namespace/definition locks were domain-specific. No scaled numbers, tax/unit constraints, product hierarchy, seeders or business data are carried over. No generic repository abstraction, CQRS bus, service locator or plugin framework is added.

## Applying the pattern to a new service

Use the [new service setup checklist](new-service.md), including its read-only service identity preflight.

1. Create from the template and rename Composer/app metadata, service and database identifiers, OpenAPI title and generated integration metadata. Keep the shared middleware, authentication, runtime, test guard and gateway/Identity generators unchanged. Generate independent secrets.
2. Read the [executable domain example](domain-example.md), then add one real domain model, dedicated request per operation, explicit controller methods, service, interface and concrete repository, mapper, DTO and typed response for your module. Bind its repository interface in the existing provider.
3. Register explicit routes in `routes/api/v1/` and `routes/api.php`; add complete controller-local OpenAPI request/response schemas and operation metadata for the existing integration generators. Run the existing OpenAPI gate, unit, database and generator checks.

Do not put Eloquent or arbitrary SQL in controllers, build listing queries in services, drive generic CRUD with resource-name strings, serialize `model->toArray()` as an API contract, or create catch-all `array $data` DTOs. HealthService is an intentional infrastructure exception, not a real-domain design. Prefer an operation-specific response over a module-wide untyped wrapper. New abstractions need actual consumers; do not introduce buses, handlers or general-purpose repository frameworks in anticipation of future modules.\n\n## Validation and security

Every operation uses a dedicated ApiFormRequest subclass with explicit rules. The shared request rejects unknown top-level fields, invalid UTF-8 and NUL text. Operation-specific rules own domain identifiers, booleans and nested fields. Language accepts a bounded language tag from `Language`, lowercases it and returns typed 400 for malformed tags.

Signature compares the exact `Signature` header with `config('services.internal.signature')` using `hash_equals`. Both sides must be nonempty. Missing configuration fails closed with the established typed 403. It supplies internal service authentication, not end-user identity or authorization. Never log signatures; inject independent secrets through the deployment's secret mechanism. Probe routes stay outside this boundary and must be isolated by private networking.

## Integration tooling

The development-only Artisan gateway and Identity generators now read Laravel's route registry and explicit operation/permission metadata. The existing runtime routing architecture is unchanged; generation never executes handlers or adds public endpoints. MainGate and Service Gateway receive separate intermediate manifests, and Identity receives the existing `subject / actions / module / description` declaration shape. A dedicated NativeMainGateRenderer now extends the same validated snapshot into an Inventory-style controller/modular route package, published through the existing atomic OutputWriter. Explicit gatewayAction metadata supports reviewed action names without duplicating API definitions. Generated PHP is development output and is never registered by this application. NativeIdentityRenderer now consumes the same mapped snapshot to produce a correctly namespaced Laravel seeder, multilingual catalog, key manifest and shared description-only PermissionSeedWriter. The existing OutputWriter independently owns native permission files and gateway files. Installed seeders validate all data before a transaction, preserve DB-generated IDs and update no role/grant/operational fields. Seeders are never installed or executed by the source service. Native Service Gateway adapters and ID allocation remain excluded; actual MainGate/Identity runtime integration remains unverified. See [integration generators](integration-generators.md) for the versioned contract, safe output handling and extension process.
