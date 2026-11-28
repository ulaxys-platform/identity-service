# Benchmarking plan

Status: NOT VERIFIED — no benchmark results are claimed. The full benchmark cannot run in this sandbox (no PHP/PostgreSQL/Redis runtime); per the task brief, executable scripts and this methodology are provided instead.

## Datasets (deterministic, synthetic)

| Scenario | Services | Permission definitions | Users   | Sessions |
| -------- | -------- | ---------------------- | ------- | -------- |
| Small    | 10       | 500                    | 1,000   | 2,000    |
| Medium   | 100      | 5,000                  | 10,000  | 20,000   |
| Large    | 500      | 25,000                 | 100,000 | 200,000  |

Generator: `scripts/benchmark/generate-dataset.php` (I5 deliverable) producing deterministic ULID-prefixed rows inside a transaction batch; identities only, no commercial records.

## Measured operations

Login throughput (Argon2id bound), token issuance, token validation (local JWKS path), introspection, authorization lookup (I3+), batch authorization (I5+), scoped assignment lookup, permission import, cache invalidation, session revocation, concurrent refresh, PostgreSQL query counts per request, Redis operations, memory consumption, p50/p95/p99 latency.

## Method

- Same hardware pinned in the report; container CPU/memory limits documented.
- `wrk`/`k6` style HTTP drivers for login/validate/introspect; PHP CLI harness for DB-heavy paths; concurrency ladder 1/8/32/128.
- Multi-instance consistency (two FPM pods, shared PostgreSQL+Redis): refresh rotation races and family-revocation visibility.
- Results published with raw numbers per run; nothing extrapolated or invented.
