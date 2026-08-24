# Hibari: capability-aware datastore compatibility layer

## Status

Closed

## Completion scope

Initial architecture complete through Phase 4.

Phase 5 (`backend portability proof`) remains an optional follow-up and was explicitly excluded from the initial-release completion condition in the original proposal.

The original design proposal is preserved verbatim at:

`issues/closed/20260821-capability-aware-datastore-compatibility-layer.proposal.md`

## Result

Hibari の initial architecture は成立した。

実証された dependency direction は次の通り。

```text
Prisma ---------> @hibari/core <--------- @hibari/kintone
WordPress ------> runtime/core <---------- @hibari/kintone
                       |
                 capability planner
                 execution plan
                 diagnostics
```

consumer は concrete kintone REST API を知らず、backend-specific constraints は backend capability / planner 境界へ閉じ込める。WordPress の SQL-oriented 要求も consumer adapter / static tooling に留まり、core を SQL database implementation へ変えていない。

## Phase completion

### Phase 0: contracts — complete

Evidence:

- `issues/closed/20260821-minimal-contracts-capability-planner.md`
- backend-neutral Schema / Query / Mutation IR
- Capability Manifest
- `native` / `emulated` / `expensive` / `unsupported`
- inspectable Execution Plan
- stable diagnostics
- pre-backend rejection

### Phase 1: kintone minimal backend — complete

Evidence:

- `issues/closed/20260821-kintone-minimal-backend.md`
- schema introspection / codec / CRUD / pagination / batching / revision concurrency
- backend limits and warnings centralized in kintone capability configuration
- transparent 500-record read pagination and 100-record write batching
- offset ceiling and unsupported semantics rejected before transport

### Phase 2: Prisma proof — complete

Evidence:

- `issues/closed/20260821-prisma-consumer-proof.md`
- real generated Prisma Client
- findMany / findUnique-equivalent / create / update / delete
- generated client -> `@hibari/prisma` -> core runtime -> `KintoneBackend` -> fake Kintone REST
- application CRUD contains no kintone App ID / field code / `$revision` / REST endpoint / pagination detail
- unsupported JOIN / aggregate / transaction / schema semantics are rejected before backend execution

### Phase 3: WordPress Core proof — complete

Representative evidence:

- `issues/closed/20260822-wordpress-db-dropin-boundary-proof.md`
- `issues/closed/20260822-wordpress-runtime-transport-proof.md`
- `issues/closed/20260822-wordpress-options-crud-proof.md`
- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- WordPress proof suite in `.github/workflows/ci.yml`

The current CI suite covers stock WordPress db.php/runtime, options, post content, postmeta, taxonomy, term creation, users, comments, media, draft/publish, tags, full bootstrap, page delete, and post-taxonomy force-delete semantics.

### Phase 4: compatibility tooling — complete

Evidence:

- `issues/closed/20260824-wordpress-compatibility-report-stable-diagnostics.md`
- `issues/closed/20260824-wordpress-plugin-source-compatibility-check.md`
- `issues/closed/20260824-schema-drift-check.md`
- `issues/closed/20260824-ci-friendly-strict-mode.md`

Completed capabilities:

- execution-free PHP plugin/source scan
- stable `HIB-WP-*` diagnostics and golden reports
- canonical `native|emulated|expensive|unsupported` report model
- core `HIB-COST-001/002` warnings for expensive-but-valid plans
- backend-neutral SchemaIR drift detection via `HIB-SCHEMA-001..005`
- default/strict CI policy
- deterministic JSON plugin check with exit codes `0/1/2`

## Acceptance criteria for the initial architecture

### Abstraction

- [x] core が Prisma / WordPress / kintone の concrete API に依存しない
- [x] consumer -> core <- backend の dependency direction が維持される
- [x] backend constraints が Capability Manifest / planner に集約される
- [x] diagnostics と runtime が同じ execution/capability model を利用する

Evidence: Phase 0 fake-backend contracts, separate `@hibari/prisma` / `@hibari/kintone` dependencies, and backend-neutral `DatastoreRuntime` / runtime-http contracts.

### Developer experience

- [x] ordinary CRUD では application code に kintone App ID / field code / revision / pagination details が現れない
- [x] unsupported semantics は backend API error より前に検出できる
- [x] expensive but valid operations は warning として区別できる
- [x] developer が必要な場合だけ execution plan を inspect できる

Evidence: Prisma generated-client cross-package proof, WordPress preflight/static checks, `HIB-COST-001/002`, and inspectable planner output including estimated requests.

### Prisma proof

- [x] basic CRUD が ordinary ORM usage として動く
- [x] portable filters / ordering / pagination が動く
- [x] unsupported relation / aggregate / transaction behavior を明示的に reject できる
- [x] kintone-specific logic が Prisma adapter に重複しない

Evidence: `issues/closed/20260821-prisma-consumer-proof.md`.

### WordPress proof

- [x] stock WordPress Core を fork しない
- [x] database drop-in boundary から Hibari を利用する
- [x] basic WordPress content operations が kintone-backed persistence で動く
- [x] plugin/query compatibility を Native / Emulated / Expensive / Unsupported に分類できる
- [x] arbitrary unsupported SQL を黙って誤実行しない
- [x] WordPress-specific requirements によって Hibari core が SQL database implementation へ変質しない

Evidence: official WordPress 7.1 tarball proofs, `db.php` drop-in, backend-neutral HTTP/runtime bridge, KintoneBackend request evidence, canonical compatibility reports/static scanner, and stable early-rejection diagnostics.

## Architecture guardrail result

The initial implementation did not cross the proposal guardrails:

- Prisma adapter does not own kintone REST details.
- WordPress adapter does not own kintone App IDs / field codes / REST transport semantics.
- core IR is not a SQL AST.
- kintone limits remain backend-owned rather than copied into consumers.
- unsupported operations are not silently converted to semantic-changing client-side fallback.
- WordPress support did not grow into a generic MySQL emulator.

## Final verification

Baseline before this closure record:

- main: `27651f45a61a9c5fc5571600383a4804f0ecc5e2`
- PR #24 completed Phase 4 strict-mode/canonical-classification work
- GitHub Actions run #262 / `32708477336`: `success`
- all 16 jobs completed successfully

The CI workflow at this point executes the root core/kintone/Prisma/integration suite plus 15 focused WordPress proof jobs.

## Follow-up boundary

Phase 5 may add one non-kintone record-oriented backend to further validate portability. It is useful additional evidence, but it is not required to consider this initial architecture complete.
