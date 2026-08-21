# Hibari Prisma consumer proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Goal

Phase 2 として、Prisma ORM 7 の通常の `PrismaClient` API から Hibari を利用し、application code が kintone 固有の App ID / field code / pagination / revision を意識せず basic CRUD を実行できることを証明する。

Prisma は SQL driver adapter を介して query engine から SQL を渡すため、Prisma 固有 SQL parsing / result-shaping は `@hibari/prisma` に閉じ込める。Hibari core を SQL AST にしない。

## Current Prisma target

- Prisma ORM 7.9.1
- custom/community driver adapter
- provider: SQLite
- `SqlMigrationAwareDriverAdapterFactory` compatible adapter surface
- generated Prisma Client を使う smoke proof

SQLite provider を使うのは Prisma が生成する SQL surface を最小に保つためであり、Hibari core が SQLite semantics を採用するという意味ではない。

## Architecture

```text
PrismaClient
    |
Prisma query engine
    |
SQLite-shaped SQL
    |
@hibari/prisma
  SQL subset parser
  result shaping
  compatibility diagnostics
    |
@hibari/core DatastoreRuntime
    |
@hibari/kintone
```

Dependency direction:

```text
@hibari/prisma  -> @hibari/core
@hibari/kintone -> @hibari/core
```

`@hibari/prisma` は `@hibari/kintone` を import しない。application composition が core runtime interface を介して両者を接続する。

## Scope

### Core

- backend-neutral `DatastoreRuntime` execution contract
- query result / mutation result contracts usable by consumers

### Prisma adapter

- Prisma 7 driver adapter factory surface
- SQLite provider
- parameterized SQL input -> Hibari Query / Mutation IR
- SELECT subset
  - projection
  - scalar equality/comparison/in filters
  - AND / OR
  - ordering
  - limit / offset
- INSERT single/multi row
- UPDATE
- DELETE
- RETURNING/result shaping required by Prisma basic CRUD
- stable `HIB-PRISMA-*` diagnostics
- explicit rejection of unsupported SQL/semantics
- no silent JOIN / aggregate / transaction emulation

### Proof

- real generated Prisma Client smoke fixture
- `findMany`
- `findUnique` equivalent
- `create`
- `update`
- `delete`
- ordinary application code contains no kintone App ID / field code
- fake `DatastoreRuntime` contract tests
- kintone backend remains a separate package
- generated Prisma Client -> `@hibari/prisma` -> `@hibari/core` -> `KintoneBackend` -> fake Kintone REST end-to-end contract

## Early detection

At minimum the adapter rejects before backend execution:

- JOIN
- GROUP BY / aggregate expressions outside the portable subset
- interactive transaction semantics
- arbitrary SQL functions/expressions
- unsupported subqueries
- schema/migration SQL through the runtime adapter

Diagnostics use stable codes, including:

- `HIB-PRISMA-SQL-001` unsupported SQL shape
- `HIB-PRISMA-JOIN-001` unsupported JOIN
- `HIB-PRISMA-AGG-001` unsupported aggregate
- `HIB-PRISMA-TXN-001` unsupported transaction
- `HIB-PRISMA-SCHEMA-001` unsupported schema/migration statement

## Design constraints

- core IR を Prisma SQL AST に変えない
- Prisma adapter に kintone App ID / field code / REST endpoint を入れない
- SQL parser は Prisma basic CRUD proof に必要な deterministic subset に限定する
- arbitrary SQL compatibility を目標にしない
- unsupported SQL を client-side full scan へ silent fallback しない
- execution plan / diagnostics は Hibari core と同じ classification model を利用する
- generated Prisma Client の型安全性を維持する

## Acceptance criteria

- [x] `DatastoreRuntime` が core に追加され、kintone backend が structural/explicit implementation として利用できる
- [x] `@hibari/prisma` が `@hibari/core` のみに依存する
- [x] Prisma 7.9.1 driver adapter contract と型互換である
- [x] basic SELECT SQL を Query IR に変換できる
- [x] basic INSERT / UPDATE / DELETE SQL を Mutation IR に変換できる
- [x] query result を Prisma SQL result set へ変換できる
- [x] JOIN / aggregate / transaction / schema SQL を backend execution 前に reject できる
- [x] fake runtime contract tests が green
- [x] generated Prisma Client で findMany/create/update/delete smoke が green
- [x] Prisma smoke application code に kintone 固有概念が現れない
- [x] existing core + kintone tests が regression green
- [x] generated Prisma Client から KintoneBackend までの cross-package integration proof が green

## Completion evidence

- `@hibari/prisma` implements the Prisma ORM 7.9.1 SQLite driver-adapter surface while depending on `@hibari/core`, not `@hibari/kintone`.
- Prisma SQL subset tests: 6/6 green.
- Generated Prisma Client CRUD smoke: 1/1 green.
- Core regression contracts: 7/7 green.
- Kintone regression contracts: 12/12 green.
- Cross-package proof: generated Prisma Client -> Hibari Prisma adapter -> core runtime -> KintoneBackend -> fake Kintone REST, 1/1 green.
- GitHub Actions CI run #38 (`32528750750`) completed `npm test` successfully on Node 22, including the cross-package integration suite.
- Integration proof commit: `3ab922554b038b2f5f61011a328693b4662c5da0`.
- Root CI now executes cross-package integration by default via commit `59351a882408011c64f87df2509fa91e3190ab2a`.
- Application CRUD code only uses ordinary Prisma model/field names; Kintone App ID, field codes, `$id`, `$revision`, REST endpoints and pagination remain behind composition/backend boundaries.

## Non-goals

- complete SQLite compatibility
- raw SQL compatibility
- nested relation query support
- aggregate/groupBy support
- interactive transactions
- Prisma Migrate against kintone
- Prisma Studio
- WordPress
- live kintone credentials
