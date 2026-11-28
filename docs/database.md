# Database architecture (I1)

PostgreSQL only (template mandate). Migrations run in explicit order under `database/migrations/`:

| Table                             | Purpose                          | Notable constraints                       |
| --------------------------------- | -------------------------------- | ----------------------------------------- |
| identity_users                    | authentication identity root     | ULID PK; status/type                      |
| identity_user_profiles            | display names, locale, timezone  | unique user_id, FK cascade                |
| identity_login_identifiers        | email/username login identifiers | unique (kind, normalized); FK cascade     |
| identity_password_credentials     | Argon2id hashes                  | unique user_id; hidden hash               |
| identity_identifier_verifications | hashed email verification tokens | unique token_hash                         |
| identity_credential_challenges    | hashed reset/setup tokens        | unique token_hash; purpose index          |
| identity_auth_sessions            | first-class sessions             | user+revoked index; expires index         |
| identity_refresh_tokens           | rotation families                | unique token_hash; family/session indexes |
| identity_platform_administrators  | interim admin grant              | unique user_id                            |
| identity_signing_keys             | key metadata                     | unique kid                                |
| identity_audit_events             | append-only audit                | action+time, actor, subject indexes       |
| identity_security_events          | append-only security             | event/user/session/time indexes           |

## Transactional operations

Login (session + refresh + audit), password change (hash + revocations + audit), reset (hash + consume + revocations + audit), bootstrap (user + identifier + grant + challenge + audit), user creation (user + profile + identifier + audit), refresh rotation (consume + append). Identifier uniqueness relies on PostgreSQL as the concurrency authority, with a domain precheck for a friendly 409 (matching the domain-example pattern for SQLSTATE 23505).

## Conventions and future work

ULID primary keys (opaque, sortable). No engine-managed duplicates: no OAuth client tables are created because no OAuth engine is installed in I1 (ADR-0001); Passport would own its tables in I2. Partitioning of the two append-only tables and an archive job are listed for the I5 scalability pass. Destructive migrations are forbidden outside disposable test databases (enforced by `DestructiveDatabaseGuard`).
