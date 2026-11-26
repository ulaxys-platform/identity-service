# Dependency security disposition

- Date: 2026-11-26
- Baseline: `composer.lock` at `072a5ad8` (82 packages, 27 dev packages)

## Advisory verification

The November 25, 2026 Symfony security release was verified against the public ecosystem during this unit:

- Symfony published 5.4.57 / 6.4.51 / 7.4.24 / **8.1.12** security releases on November 25, 2026; the 8.1.12 release note explicitly lists CVE-2026-133743 (HttpFoundation/HttpKernel forwarded-header/URL handling) among the fixes.
- Additional 8.1.12 fixes reported by the ecosystem include CVE-2026-134790 (Security-HTTP login redirect), CVE-2026-134791 (ExpressionLanguage lexer) and CVE-2026-134792 (httpoxy in CLI workers).

The stated patched boundaries (Symfony 7.4.24 and 8.1.12) are therefore confirmed for the 8.1.x branch.

## Affected packages in the actual lockfile

| Package | Locked | Boundary | Verdict |
| --- | --- | --- | --- |
| symfony/http-kernel | v8.1.11 | >= 8.1.12 | AFFECTED |
| symfony/mailer | v8.1.10 | >= 8.1.12 | AFFECTED |
| symfony/routing | v8.1.11 | >= 8.1.12 | AFFECTED |
| laravel/framework | v13.43.0 | not reported affected | OK |

Other `symfony/*` components in the lock (console 8.1.11, error-handler 8.1.10, http-foundation 8.1.11, mime 8.1.10, process 8.1.10, string 8.1.10, translation 8.1.11, yaml 8.1.11, etc.) are also below 8.1.12; a targeted update of the three reported packages plus their shared dependencies brings the affected set forward without unrelated major-version jumps.

## Required repository-side remediation

The implementation sandbox has no PHP or Composer runtime (see docs/acceptance.md), so `composer.lock` cannot be regenerated honestly here. Hand-editing a lockfile would corrupt its integrity hash and is rejected. Maintainers must run, on a machine with PHP 8.5 + Composer 2:

```sh
composer update symfony/http-kernel symfony/http-foundation symfony/mailer symfony/routing --with-all-dependencies
composer validate --strict
composer audit
php vendor/bin/phpunit --testsuite Foundation
php vendor/bin/pint --test
composer openapi
```

Then commit the refreshed `composer.lock`. `composer.json` already permits the patched versions (Laravel ^13.0 constrains `symfony/*` to ^8.1), so no manifest change is required; only the lock moves. Until that commit lands, `composer audit` reports the three advisories above; do not suppress them (`composer audit --ignore` is prohibited by the task brief and was not used).

## Dependency policy for Identity V2 (I1)

The original candidate added no Composer dependencies. This is not an accepted engine policy: maintained authentication dependencies are required if selected by I0. See `docs/review-remediation.md`. The token core uses PHP core `ext-openssl`, `password_hash` and `random_bytes` only, keeping the frozen install and this audit surface unchanged (ADR-0001). Planned I2 dependencies (Laravel Passport, WebAuthn and TOTP libraries) must each go through: lock refresh, `composer audit`, platform requirement check and the regression suite before merge.
