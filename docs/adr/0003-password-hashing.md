# ADR-0003: Password hashing and credential policy

- Status: Accepted
- Date: 2026-11-26

## Context

Local authentication must not store plaintext or reversibly encrypted passwords, must resist offline cracking, and must not leak account existence. No custom hashing is permitted.

## Decision

- **Argon2id** via PHP's native `password_hash()`/`password_verify()` (`config/hashing.php` driver `argon2id`), parameters following the OWASP Password Storage Cheat Sheet minimum: memory 19456 KiB (~19 MiB), time cost 2, threads 1. Parameters are configuration, not code; rehashing on login (`Hash::needsRehash`) upgrades legacy entries opportunistically.
- **Password policy** (NIST SP 800-63B aligned): minimum 12 characters, maximum 128; no forced composition rules; blocklist of common passwords plus rejection of passwords derived from the account identifier or display name; policy messages localized en/tr/ar.
- **Enumeration resistance**: unknown identifier, missing credential and wrong password all produce the identical response body and status; when no credential exists the service still runs one Argon2id verification against a fixed dummy hash so timing does not reveal account existence.
- **Reset/setup tokens**: 32-byte random secrets, stored as SHA-256 hashes, single-use (`consumed_at`), short-lived (60 minutes reset, 24 hours first-time setup), delivered through the `TokenDeliveryInterface` abstraction; the default driver delivers nothing (audit-only) and the log driver is a local-development aid, never production.

## Consequences
- Hash verification cost (~30-60 ms) bounds login throughput; capacity planning numbers are a benchmarking task (docs/acceptance.md marks it NOT VERIFIED).
- Argon2id memory cost must fit container limits; production images are documented in docs/deployment.md.
