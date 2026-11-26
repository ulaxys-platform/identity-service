# Unified Developer Tooling V1

The template and generated PHP services include a Docker-only code-quality launcher.
No host Composer, PHP or Node is required; Docker and Python 3.10+ are required.

## Commands

Windows PowerShell (`.\tools` is resolved through `tools.cmd`):

```powershell
.\tools install
.\tools lint
.\tools lint php
.\tools lint prettier
.\tools lint syntax
.\tools test
.\tools test tooling
.\tools test generator
.\tools test foundation
.\tools test database
.\tools cache:status
.\tools clear:cache
.\tools clear:all
.\tools smoke
.\tools acceptance generated
.\tools format php
.\tools format prettier
.\tools format
```

Linux/macOS: use `sh tools.sh` with identical subcommands (no executable bit required).

`install` builds the repository's existing pinned PHP `development` stage,
installs the frozen Composer lock into an isolated per-checkout Docker vendor
volume and downloads Prettier 3.6.2 into a shared npm cache volume.

`lint` is read-only; `format` modifies checkout files. PHP and Prettier are
separate formatters. PHP uses the Laravel Pint dependency from `composer.lock`.
Non-PHP formatting uses the exact Prettier version defined in the launcher.
The production image and live application containers are never modified.

## Local cache and configuration maintenance

The public `tools` CLI also offers **checkout-scoped** cleanup without
Laravel boot, Docker access or a running service:

```powershell
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

On Linux/macOS, use `sh tools.sh` with the same command names.

- `clear:cache` removes only Python bytecode cache directories inside
  known source trees and local PHPUnit/pytest result caches.
- `clear:config` removes `bootstrap/cache/config.php` only.
- `clear:routes` removes Laravel's `bootstrap/cache/routes*.php` caches.
- `clear:events` removes only the cached event discovery manifest.
- `clear:compiled` removes Laravel's cached services/packages manifests.
- `clear:views` removes only compiled `*.php` files directly beneath
  `storage/framework/views`.
- `clear:tests` removes only local test result caches.
- `clear:all` combines these scopes; `cache:status` is read-only.

All deletion targets are bounded to known paths, symlinks are not followed,
and cleanup is safe to repeat. No Redis, database, global Composer/npm
cache, Docker volume/container, user upload, source file, generated
integration output, or `storage/app` file is deleted. To clear a _running_
service's remote application cache, use its explicitly authorized deployment
operations instead; this command intentionally does not call Laravel's
`cache:clear` or `optimize:clear` against an existing environment.

## Running tests through a single portable command

Use the same public CLI entry points on each OS; do not invoke raw Python
test module paths or Laravel PHPUnit directly as the normal workflow.

```powershell
.\tools test                 # All test groups; disposable database
.\tools test tooling         # Tooling regressions only; no Docker
.\tools test generator       # Generator regressions only; no Docker
.\tools test foundation      # PHPUnit Foundation
.\tools test database        # PHPUnit Database (disposable PostgreSQL)
```

For ordinary runs, output is intentionally compact: each test group shows
one PASS/FAIL row and the command ends with a summary. Successful subprocess
logs and individual test method names are hidden. On failure the full
diagnostic output is printed and the exit code is preserved.

To inspect each test name and the underlying command output, run
`.\tools test tooling --verbose` (Linux/macOS:
`sh tools.sh test tooling --verbose`). The same flag is available for
`verify`. No external log viewer or CI account is required.

For Linux/macOS, use `sh tools.sh test` and the same optional suite names.
`test` runs the test suites only. `verify` is a broader local quality gate
covering Composer, lint, security, tests, generators and OpenAPI. Both fail on
the first failing command and preserve its exit status. Generator/tooling
selectors exist only on the template checkout, not on domain services.

## Production and generated-service acceptance

The template includes two **explicit, isolated** acceptance commands:

```powershell
.\tools smoke
.\tools acceptance generated
```

Use `sh tools.sh smoke` or `sh tools.sh acceptance generated` on
Linux/macOS. Both require Docker, Python 3.10+, Dockerfile BuildKit support,
and internet access for any uncached image dependencies.

- `smoke` builds the checked-out service's production Docker image and runs
  the real runtime smoke contract from `tests/Runtime/smoke-container.py`
  against an independently created PostgreSQL 17 instance. This validates
  health probes, Signature enforcement, OpenAPI, non-root execution,
  production-only dependencies, redacted logs, fail-closed startup, and
  graceful shutdown. No production ports are published.
- `acceptance generated` is a **template-only** check. It creates an
  independent `acceptance-service` from the template's **committed Git
  HEAD** inside a fresh temporary directory, verifies its generation
  provenance, checks inherited launchers, runs the generated service's
  `install`, `test`, and `verify` commands, checks cache status, builds
  its production Docker image, and executes the same runtime smoke contract.
  Uncommitted working-tree changes are deliberately not included.
- Each invocation uses randomized Docker network, PostgreSQL container
  and image names, disposable credentials, and a temporary generated
  service tree. The generated service's temporary development image
  and private Composer vendor volume are also removed after the run.
  Shared Docker build layers and the named npm download cache are retained.
  No existing Compose service, project database, uploaded file, deployment
  resource or Docker volume is pruned.

The existing `test` and `verify` commands **do not** imply these two
acceptance gates have passed. Both commands must return exit code zero to
claim full local production and generated-service acceptance. GitHub Actions
and explicit macOS operator acceptance remain independent of local checks.

## Local CI acceptance without GitHub Actions

Run `.\tools verify` (or `sh tools.sh verify`) after `install` to perform
Composer validation, security audit, platform requirements, PHP syntax, Pint,
Prettier, PHPUnit Foundation and Database, OpenAPI and integration-generator
dry-runs. On the template checkout it also runs the Python generator regression
suite. The command fails on the first unsuccessful check and returns a nonzero
exit code.

The Database suite uses a **new disposable PostgreSQL 17 container** on a
**unique Docker internal network** without published ports. It uses temporary,
random credentials and explicit `APP_ENV=testing`,
`SERVICE_ALLOW_DESTRUCTIVE_TESTS=true` and `DB_DATABASE=service_test`.
The PostgreSQL container and network are removed even when the tests fail.
A cached Laravel `bootstrap/cache/config.php` blocks verification to prevent
using stale production connection settings. No existing local database,
Compose service, or deploy installation is touched.

A reduced acceptance without PostgreSQL remains available through
`.\tools verify --skip-database` (Linux/macOS:
`sh tools.sh verify --skip-database`) for diagnostics. This does
**not** qualify as complete CI parity.

Verification can regenerate OpenAPI artifacts in the checkout. Production
image smoke, real generated-service full acceptance and CI runner validation
remain separate gates. Do not represent `verify` alone as complete GitHub CI.

## Ownership and deployment coordination

PHP Service Template owns per-service checks, their Docker tooling and the
portable launchers inherited by generated services. `ulaxys-deploy` may
orchestrate them across services later, but it must not own service-specific
Pint/Prettier/test logic or require running service containers.

## Scope and limitations

- PHP: Pint default Laravel preset (same command as CI). Syntax via `lint syntax`.
- Prettier: Markdown, JSON, YAML and other Prettier-supported files, except
  `.prettierignore` paths. PHP, vendor, generated content and Composer lock
  are excluded to prevent conflicts and generated-file churn.
- Existing text files may need a one-time review-only formatting baseline.
- Configuration changes should run `lint` in CI after the baseline is accepted.
- The Docker volume is per-checkout; when `composer.lock` changes run `install` again.
- Do not use the tools on secrets or production application images.

## Troubleshooting

If Pint says `Run tools install first`, run `tools install`. If a Docker
daemon is unavailable, start Docker Desktop. If Prettier cannot download on
the first run, check network access; subsequent installs reuse its cache.
