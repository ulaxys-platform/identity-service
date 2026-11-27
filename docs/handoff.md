# Implementation handoff — R0 approved

Read [R0](r0-approved-architecture.md), canonical ADR-0015 in https://github.com/ulaxys-platform/ulaxys-docs/pull/3, and [review findings](review-remediation.md). Continue PR #1 only; inspect current HEAD. No merge, regeneration, visibility change or PR #2 implementation. Next deliver real Keycloak technical PoC before engine-dependent refactor. Preserve useful domain/template code, resolve IDV2-001–016, retain quality gates, no duplicate authority or direct provider DB access. Preserve legacy integration and report exact SHA, tests and blockers in Turkish.

## Historical handoff (superseded)

# Implementation handoff

## What the reviewing team receives

- Branch `feature/enterprise-identity-v2` (commits: ADRs; runtime policy config; advisory/defect dispositions) — or this package when repository write access is unavailable (INSTALL.md).
- I1 implementation unit: 12 migrations, 12 models, 9 repository pairs, token core + authentication services, 2 middlewares, 12 form requests, 7 controllers, 7 route modules, 2 operator commands, en/tr/ar localization, complete integration metadata, 11 test classes, 25+ documents.
- Honest verification matrix in docs/acceptance.md.

## Immediate actions for maintainers

1. Apply the package / merge the branch into review; run the verification commands in docs/acceptance.md on a PHP 8.5 machine.
2. Refresh `composer.lock` for the Symfony 8.1.12 boundary (docs/dependency-security.md) and run `composer audit`.
3. Run `composer openapi` and review the generated artifact; run `php artisan ulaxys:generate-integration --all --strict --dry-run` and review declaration output.
4. Review the reconstructed CI workflow against the template's original (docs/template-defects.md) and enable Actions on the repository.

## Engine decision point (I2 gate)

Before I2: evaluate Laravel Passport (league/oauth2-server) in the real runtime for OAuth2 grants, then build the OIDC layer (discovery, ID tokens, UserInfo) on top; adopt `web-auth/webauthn-lib` for passkeys and a maintained TOTP library. I1 token keys remain the JWKS source of truth during the migration.

## Integration points

- Gateway team: docs/gateway-integration.md (v1 contract, independently implementable).
- Data/Business Partner services: docs/external-resource-contract.md (verification operations, staleness/failure policy).
- Deploy: docs/deployment.md (alias `identity-v2`, key/bootstrap/migration steps).
