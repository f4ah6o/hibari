# Hibari Prisma consumer proof

## Status

Open

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

## Early detection

At minimum the adapter must reject before backend execution:

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

- [ ] `DatastoreRuntime` が core に追加され、kintone backend が structural/explicit implementation として利用できる
- [ ] `@hibari/prisma` が `@hibari/core` のみに依存する
- [ ] Prisma 7.9.1 driver adapter contract と型互換である
- [ ] basic SELECT SQL を Query IR に変換できる
- [ ] basic INSERT / UPDATE / DELETE SQL を Mutation IR に変換できる
- [ ] query result を Prisma SQL result set へ変換できる
- [ ] JOIN / aggregate / transaction / schema SQL を backend execution 前に reject できる
- [ ] fake runtime contract tests が green
- [ ] generated Prisma Client で findMany/create/update/delete smoke が green
- [ ] Prisma smoke application code に kintone 固有概念が現れない
- [ ] existing core + kintone tests が regression green

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
