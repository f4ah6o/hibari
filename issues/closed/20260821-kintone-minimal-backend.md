# Hibari kintone minimal backend

## Status

Closed

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

- [x] package boundary が `consumer -> core <- kintone backend` を維持する
- [x] form introspection から identity/revision/unique/subtable/relation hint を Schema IR にできる
- [x] application field name と kintone field code を分離できる
- [x] primitive record encode/decode ができる
- [x] basic filter/order/projection を kintone query に compile できる
- [x] 500件超 read が silent truncation せず pagination される
- [x] 100件超 insertMany が transparent batching される
- [x] offset 10,000 制約を request 前に検出できる
- [x] update/delete が selector resolution を経て正しい record ID に適用される
- [x] upsert が configured/introspected unique field 以外を事前 reject する
- [x] revision mismatch protection を REST request に投影できる
- [x] unsupported / expensive backend semantics が structured diagnostics として得られる
- [x] core contract tests と kintone contract tests が green

## Non-goals

- Prisma integration
- WordPress integration
- complete SQL compatibility
- schema migration/app settings apply
- Attachment upload/download
- Process Management
- full Subtable mutation semantics
- live kintone acceptance with user credentials

## Completion evidence

完了。

Repository boundary:

- root を npm workspace 化
- `packages/core` -> `@hibari/core`
- `packages/kintone` -> `@hibari/kintone`
- dependency direction は `@hibari/kintone -> @hibari/core` のみ

Kintone backend:

- `capabilities.ts`: page 500 / write batch 100 / offset 10000 / concurrency 100 / request budget 10000 を一元化
- `schema.ts`: Get Form Fields response -> Schema IR、`$id` / `$revision` / unique / Subtable / Lookup / Related Records
- `codec.ts`: field-code mapping と primitive encode/decode
- `query.ts`: portable filter / ordering / projection / seek cursor compile、offset ceiling preflight、unbounded-read warning
- `backend.ts`: introspection、transparent cursor/offset pagination、CRUD、batching、selector resolution、semantic upsert、revision concurrency
- `transport.ts`: injected transport と fetch transport
- backend-specific stable diagnostics: `HIB-KINTONE-*`

Semantic safeguards:

- 500件超 read を1 requestで黙ってtruncateしない
- finite offset pagination は複数 request に分割し、最終 page offset が10000を超える場合は transport 前に `HIB-KINTONE-OFFSET-002`
- unbounded offset read は `HIB-KINTONE-OFFSET-001`
- upsert は create/update payload をmergeせず、unique selectorをpreflightして存在有無で insert/update を分岐
- non-unique upsert は `HIB-KINTONE-UPSERT-001` で request 前 reject
- optimistic concurrency token は `$revision` のみ
- cursor は requested limit で早期終了した場合に明示 cleanup

検証:

```text
$ npm test

@hibari/core
# tests 7
# pass 7
# fail 0

@hibari/kintone
# tests 11
# pass 11
# fail 0
```

合計 18/18 green。

Fake transport tests は schema introspection、codec、query escaping、cursor large read、100-record write batching、revision update、upsert preflight/semantics、500件超 finite offset paging、10,000 offset ceiling preflight を検証する。

Live credential acceptance はこの issue の non-goal のまま。次フェーズで consumer proof に入る前に必要なら独立 issue として追加する。
