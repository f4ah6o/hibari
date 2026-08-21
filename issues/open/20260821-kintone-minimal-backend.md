# Hibari kintone minimal backend

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Goal

Phase 0 で固定した backend-neutral core contract を最初の real backend で dogfood する。

kintone 固有の App ID / field code / REST endpoint / pagination / revision / request limit は `@hibari/kintone` に閉じ込め、application/consumer が直接知る必要のない境界を作る。

## Scope

- `@hibari/core <- @hibari/kintone` の package boundary
- kintone Capability Manifest
- Get Form Fields -> Schema IR introspection
- primitive field codec
- `$id` / `$revision`
- unique field / Subtable / Lookup / Related Records の schema projection
- portable scalar filter compiler
- field mapping / projection / ordering
- 500-record read limit の透明 pagination
- cursor-based large reads
- finite offset pagination and 10,000 offset ceiling preflight
- create / createMany
- update / updateMany
- delete
- semantic upsert
- optimistic concurrency via `$revision`
- injectable transport + fetch transport
- backend-specific stable diagnostics
- fake transport contract tests

## Hard limits captured in the backend manifest

- Get Records: 500 records/request
- offset: maximum 10,000
- multi-record write/delete: 100 records/request
- concurrent REST requests: 100/domain
- REST request budget: 10,000/app/day
- cursor: maximum 10 active/domain, 500 records/read

## Design constraints

- core は kintone API を import しない
- kintone backend は core IR / planner を consume する
- App ID / field code は binding/configuration に閉じ込める
- backend limitation を silent truncation / silent client-side semantic change で隠さない
- 500件超 read / 100件超 write は意味を保てる範囲で透明に分割する
- offset ceiling を跨ぐ request は transport 実行前に reject する
- upsert の create/update payload を merge して意味を変えない
- optimistic concurrency は `$revision` のみを accepted token とする
- live credential を contract tests の前提にしない

## Acceptance criteria

- [ ] package boundary が `consumer -> core <- kintone backend` を維持する
- [ ] form introspection から identity/revision/unique/subtable/relation hint を Schema IR にできる
- [ ] application field name と kintone field code を分離できる
- [ ] primitive record encode/decode ができる
- [ ] basic filter/order/projection を kintone query に compile できる
- [ ] 500件超 read が silent truncation せず pagination される
- [ ] 100件超 insertMany が transparent batching される
- [ ] offset 10,000 制約を request 前に検出できる
- [ ] update/delete が selector resolution を経て正しい record ID に適用される
- [ ] upsert が configured/introspected unique field 以外を事前 reject する
- [ ] revision mismatch protection を REST request に投影できる
- [ ] unsupported / expensive backend semantics が structured diagnostics として得られる
- [ ] core contract tests と kintone contract tests が green

## Non-goals

- Prisma integration
- WordPress integration
- complete SQL compatibility
- schema migration/app settings apply
- Attachment upload/download
- Process Management
- full Subtable mutation semantics
- live kintone acceptance with user credentials
