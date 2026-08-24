# Hibari backend-neutral schema drift check

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Phase

Phase 4: compatibility tooling

## Goal

application/consumer が期待する `SchemaIR` と backend introspection から得た `SchemaIR` を、backend-neutral core contract だけで deterministic に比較し、runtime failure より前に schema drift を診断できるようにする。

最初の real-backend proof は既存 kintone `schemaFromFormFields()` の出力を使うが、diff implementation 自体は kintone API/App ID/field code を知らない。

## First proof scope

Required incompatibilities:

- expected model missing
- expected field missing
- scalar/embedded kind mismatch
- scalar type mismatch
- expected nullable/mutable contract mismatch when explicitly specified
- identifier mismatch
- expected concurrency-token mismatch

Extra backend models/fields are not errors in this first proof; the compatibility question is whether the expected application schema is satisfied by the backend schema.

Stable diagnostics:

- `HIB-SCHEMA-001`: model missing
- `HIB-SCHEMA-002`: field missing
- `HIB-SCHEMA-003`: field contract incompatible
- `HIB-SCHEMA-004`: identifier mismatch
- `HIB-SCHEMA-005`: concurrency-token mismatch

## Acceptance criteria

- [x] reusable backend-neutral `checkSchemaDrift(expected, actual)` exists in core
- [x] identical/compatible schema produces no diagnostics
- [x] diagnostics are deterministic by expected model/field order
- [x] stable schema diagnostic codes carry model/field path and details
- [x] no concrete backend metadata is required by the diff implementation
- [x] kintone introspection output can be checked through the same core function
- [x] drift is detected without issuing record CRUD requests
- [x] existing core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- no schema mutation/apply in this child
- no kintone-specific comparison logic in core
- no automatic deletion/creation of backend fields
- no equality requirement for backend extension metadata
- no strict-mode policy yet; this child produces drift evidence only

## Completion evidence

- implementation revision: `1d5fd3b6c3e5a1855b47c89a4c3eae81d600e6a2`
- core contract tests cover `HIB-SCHEMA-001` through `HIB-SCHEMA-005`
- kintone `schemaFromFormFields()` output is checked through the same core `checkSchemaDrift()` path
- no schema mutation or record CRUD is performed by the drift checker
- GitHub Actions CI run `32689800178` / run #258 completed successfully
- all 16 CI jobs completed with `success`, including `test` and all WordPress proof jobs
