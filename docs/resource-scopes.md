# Resource scopes

Status: DEFERRED to I3/I4 (schema above in docs/authorization-model.md; external verification contract in docs/external-resource-contract.md).

## Pre-drawn scope types

Platform, Organization, Department, Business Partner, Master Account, Operational Account, Legal Party, Service, plus explicitly registered additional types.

## Invariants (must be preserved when implementing)

- External scopes reference stable identifiers owned by the authoritative domain service; commercial code strings (AOM/AOC/...) are never authorization keys and Identity never generates them.
- Master-account access never implies child operational-account access without an explicit verified inheritance policy.
- Legal-party access is never inferred from commercial relationships; accountant/broker links in Business Partner never auto-grant IAM permissions.
- Business relationships and IAM grants are different concepts; when the authoritative service is unavailable, authorization that depends on it is denied.
