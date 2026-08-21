# Hibari: capability-aware datastore compatibility layer

## Status

Open

## Summary

Hibari は、アプリケーションや ORM からデータストア固有の差異をできるだけ隠しつつ、意味的に成立しない操作・高コストな操作・データストア固有の制約だけを実行より前の早い段階で検出する、capability-aware な datastore compatibility layer を目指す。

最初の backend は kintone とする。

ただし、Hibari 自体を「kintone ORM」や「kintone 用 SQL 互換層」にはしない。kintone は最初の実証対象であり、core は特定の ORM / SQL / backend に依存しない形を目指す。

最初の consumer proof は次の 2 つとする。

1. Prisma / ORM
   - 普通の ORM として CRUD / filter / ordering / pagination 等を利用できること
   - アプリケーションコードでは通常 kintone を意識しなくてよいこと
2. WordPress
   - stock WordPress を fork せず、`wp-content/db.php` 等の DB drop-in 境界から Hibari を利用すること
   - WordPress Core と互換な範囲を明示し、plugin/theme の datastore compatibility を事前検査できること

この 2 consumer を通すことで、Hibari core が「ORM → kintone 変換器」に閉じず、既存アプリケーションにも利用できる generic compatibility/runtime layer であることを実証する。

---

## Motivation

kintone を業務データストアとして利用したい場合、アプリケーション側が直接 kintone REST API、App ID、field code、pagination、revision、API 制限などを意識すると、通常のアプリケーション開発から大きく外れてしまう。

一方、kintone を単純に RDB / SQL database として偽装すると、JOIN、transaction、aggregation、offset、大量 scan、subtable 等の semantic mismatch を隠してしまい、実行時・本番時に初めて破綻する。

Hibari はこの両極端を避ける。

- happy path では普通の ORM / application API を使える
- backend 固有の詳細は adapter/runtime が吸収する
- 吸収できない差異は隠さない
- 不可能・危険・高コストな操作はできるだけ早く検出する
- backend の制約を consumer ごとの ad-hoc 実装にしない

---

## Product principle

Hibari の中心原則は次の 1 文とする。

> 普通の操作では backend を意識させず、backend の境界を越える操作だけを早期に露出させる。

### Transparent

Hibari が安全に吸収できる差異。

例:

- record pagination
- batch read / write
- optimistic concurrency / revision
- retry
- request concurrency control
- backend field code と application field name の mapping
- cursor / seek based pagination への変換

通常は利用者へ backend 固有事項として露出しない。

### Warning

意味は保てるが、高コスト・上限接近・性能劣化の可能性がある操作。

例:

- large scan
- large offset
- client-side evaluation
- N+1 になりうる relation loading
- request budget を大きく消費する execution plan

開発時・CI・`check`・`explain` 等で警告する。

### Error

backend 上で意味を正しく再現できない、または Hibari が semantic equivalence を保証できない操作。

例:

- unsupported JOIN semantics
- unsupported aggregate semantics
- unsupported interactive transaction
- backend が保証できない foreign key constraint
- arbitrary SQL function / expression

可能な限り API request 実行前に reject する。

---

## Architecture

```text
                Consumers
                   |
        +----------+-----------+
        |                      |
     Prisma                  WordPress
     adapter                  adapter
        |                      |
        +----------+-----------+
                   |
            Normalized IR
                   |
          Capability Planner
                   |
            Execution Plan
                   |
              Backend API
                   |
                kintone
```

将来的には以下のような拡張を許容する。

```text
Consumers:
  Prisma
  Drizzle
  WordPress
  REST / GraphQL
  other applications

Backends:
  kintone
  other record-oriented datastores
```

consumer と backend を直接結合しない。

---

## Core contracts

### 1. Schema IR

backend の schema を application / ORM 側へ投影するための共通表現。

最低限:

- model / entity
- scalar field
- identifier
- unique constraint capability
- revision / concurrency token
- embedded collection
- relation hint
- field mutability
- backend extension metadata

kintone では概ね次を対応させる。

```text
App             -> model / entity
Record          -> row / entity instance
Field           -> scalar / value field
$id             -> backend identity
$revision       -> concurrency token
unique field    -> unique capability
Subtable        -> embedded collection candidate
Lookup          -> relation hint / backend extension
```

Subtable や Lookup を無理に RDB relation として扱わない。

### 2. Query IR

SQL AST や Prisma 固有 AST を core にしない。

例:

```text
Query
  model
  projection
  filter
  ordering
  limit
  cursor
```

filter は最小の portable expression set から開始する。

- eq / ne
- gt / gte / lt / lte
- in
- and / or

必要な機能は consumer/backend proof を通じて追加する。

### 3. Mutation IR

最低限:

- insert
- insert many
- update
- update many
- delete
- upsert
- optimistic concurrency condition

### 4. Capability Manifest

backend 固有制約の single source of truth とする。

概念例:

```text
query:
  filter
  ordering
  cursor
  offset
  join
  aggregate

mutation:
  batch
  upsert
  optimisticConcurrency

transaction:
  atomicBatch
  interactive

limits:
  pageSize
  offset
  batchSize
  requestConcurrency
  requestBudget
```

数値や feature availability を consumer / linter / runtime に重複実装しない。

### 5. Capability Planner

Normalized IR を backend capability と照合して execution plan を生成する。

各 operation / expression を少なくとも次に分類する。

- `native`: backend へ直接 pushdown 可能
- `emulated`: Hibari が意味を保って再現可能
- `expensive`: 正しいが高コストまたは制限に近い
- `unsupported`: semantic equivalence を保証できない

### 6. Execution Plan

runtime 実行の前に inspect 可能な形にする。

例:

```text
User.findMany

classification: native
filter: pushdown
ordering: pushdown
pagination: cursor
estimated requests: 2
client-side filtering: none
warnings: none
```

execution plan は diagnostics / CI / `explain` と runtime の両方が同じものを利用する。

---

## Early detection

kintone REST API を叩いて初めて制約が分かる設計にしない。

制約検出は可能な限り次の順序で行う。

```text
1. schema introspection / generation
2. static compatibility check
3. application startup validation
4. query planning
5. runtime execution
```

### Schema-time diagnostics

例:

- ORM の unique operation に必要な一意性を backend schema が保証していない
- subtable を independent relational table として扱おうとしている
- immutable / computed field に write mapping がある
- application schema と backend schema が drift している

### Static check

CLI の中心機能として以下を想定する。

```text
hibari check
hibari check --strict
```

目標出力例:

```text
$ hibari check

Schema
  ok

Queries
  47 native
   2 expensive
   0 unsupported

Warnings
  src/report.ts:81 large scan may require many backend requests
```

consumer が source-level static analysis を提供できない場合も、known query fixtures / generated plans / runtime registration 等で可能な範囲の preflight を行う。

### Explain

利用者が必要なときだけ backend execution details を確認できるようにする。

```text
hibari explain ...
```

または consumer integration が可能なら query-level `explain()` を提供する。

通常の application code に backend 詳細を露出するための API にはしない。

---

## kintone backend

最初の backend として kintone を実装する。

### Responsibilities

- App / Form schema introspection
- application field <-> field code mapping
- query compilation
- record encoding / decoding
- pagination
- batch read/write
- optimistic concurrency
- retry / throttling / concurrency control
- capability manifest
- backend-specific diagnostics
- schema drift detection

### Important design rule

kintone 固有の制約値・仕様・例外処理を Prisma adapter や WordPress adapter へ漏らさない。

consumer は Hibari の capabilities / plan classifications を見る。

### Schema change

自動 schema sync を無条件に行わない。

概念的には次を分ける。

```text
pull
  backend -> Schema IR

diff
  desired schema <-> backend schema

plan
  migration / settings change plan

apply
  explicit mutation
```

kintone の設定反映 semantics は backend implementation に閉じ込める。

---

## Consumer proof 1: Prisma / ORM

### Goal

application developer が通常の CRUD を書いている限り、kintone を意識せず利用できることを証明する。

目標イメージ:

```ts
const users = await db.user.findMany({
  where: {
    status: "active",
    age: { gte: 18 },
  },
  orderBy: {
    createdAt: "desc",
  },
  take: 100,
});
```

application code に App ID、field code、REST pagination、revision handling を持ち込まない。

### v0 proof scope

Read:

- find unique equivalent
- find many
- portable scalar filters
- ordering
- limit
- cursor

Write:

- create
- create many
- update
- update many where semantically safe
- delete
- upsert where backend uniqueness is guaranteed
- optimistic concurrency

Diagnostics:

- unsupported relation query
- unsupported aggregate
- dangerous offset / scan
- unsupported transaction semantics

### Important constraint

Prisma integration が SQL を中間形式として要求する場合、SQL parsing / translation は Prisma adapter 側の concern とする。

```text
Prisma-specific SQL / AST
        |
Prisma adapter
        |
Normalized Hibari IR
```

Hibari core を SQL AST にしない。

---

## Consumer proof 2: WordPress on kintone

### Goal

MySQL/MariaDB を永続データストアとして使わず、Hibari + kintone backend で stock WordPress の実用的な subset を動かす。

WordPress 自体を kintone 向けに fork しない。

境界は `wp-content/db.php` 等の database drop-in / wpdb-compatible adapter を優先する。

```text
WordPress Core / Plugin / Theme
            |
          wpdb
            |
      Hibari WP adapter
            |
       Normalized IR
            |
    Capability Planner
            |
      kintone backend
```

### Why this proof matters

Prisma だけでは Hibari core が typed ORM happy path に最適化されすぎる可能性がある。

WordPress は以下を強く利用する。

- legacy SQL-oriented data access
- metadata / EAV
- plugin-defined access patterns
- query composition
- application code not designed for kintone

この consumer を通すことで、Hibari が既存アプリケーションを受け止められる generic compatibility layer であるか検証する。

### Initial WordPress target

最初から「あらゆる WordPress plugin を無修正で動かす」ことを目標にしない。

初期 target:

- stock WordPress Core
- standard theme
- post CRUD
- page CRUD
- draft / publish state
- users
- options
- categories / tags
- comments
- media metadata の基本操作

compatibility のない plugin は壊れてから知るのではなく、導入前または CI で検出する。

### WordPress compatibility classes

plugin/theme/query を次に分類できるようにする。

- Native
- Emulated
- Expensive
- Unsupported

例:

```text
Plugin: example-plugin

compatible: no

requires:
  unsupported JOIN semantics
  unsupported aggregate

warnings:
  high-cost postmeta scan
```

### wp_postmeta / EAV

最初の主要設計課題として扱う。

候補:

1. separate metadata app
2. embedded metadata
3. queryable/indexed metadata の field projection
4. hybrid strategy

WordPress 専用 hack として決め打ちせず、generic な dynamic attributes / EAV projection capability として扱えるかを検証する。

---

## Portable profile and backend extensions

Hibari は backend 固有機能を完全禁止しない。

ただし portable application と backend-specific application を区別できるようにする。

概念例:

```text
profile: portable
```

では portable capability set のみを許可する。

```text
profile: kintone
```

では Lookup、Attachment、Process 等の backend extension を opt-in で利用可能にする余地を残す。

backend extension を core portable semantics に混ぜない。

---

## Non-goals

少なくとも初期フェーズでは以下を目標にしない。

- kintone 上に完全な SQL database を実装する
- MySQL / PostgreSQL との完全互換
- 任意 SQL を必ず実行可能にする
- unsupported operation を大量 client-side processing で無理に再現する
- kintone の App / field / API details を application layer へ露出することを前提にする
- すべての WordPress plugin を無修正で動かす
- consumer ごとに別の kintone implementation を持つ
- capability violation を runtime API error まで遅延させる

---

## Proposed repository boundaries

実装言語・package manager は実証前に固定しすぎないが、責務は概ね次のように分離する。

```text
core/
  schema
  query
  mutation
  capabilities
  planner
  diagnostics

backends/
  kintone/
    introspection
    compiler
    codec
    executor
    limiter
    capabilities

consumers/
  prisma/
  wordpress/

cli/
  check
  explain
  pull
  diff
  plan
```

実際の言語/ecosystemに合わせて naming/layout は調整してよい。

重要なのは dependency direction を維持すること。

```text
consumer -> core <- backend
```

consumer -> backend の直接依存を避ける。

---

## Diagnostics contract

warning/error は単なる文字列にせず、stable reason code を持つ。

概念例:

```text
HIB-SCHEMA-001
HIB-QUERY-001
HIB-COST-001
HIB-TXN-001
```

diagnostic は最低限以下を持つ。

- code
- severity
- operation / target
- reason
- backend capability involved
- remediation hint when available
- source location when consumer can provide it

同じ constraint を CLI / CI / runtime で同じ code として扱えるようにする。

---

## Phased implementation

### Phase 0: contracts

- Schema IR
- Query IR
- Mutation IR
- Capability Manifest
- Capability Planner
- Execution Plan
- diagnostics contract
- backend-neutral tests

この段階では巨大な ORM compatibility layer を作らない。

### Phase 1: kintone minimal backend

- schema introspection
- primitive field codec
- identity / revision
- basic filters
- ordering
- pagination
- CRUD
- batch
- capability diagnostics

### Phase 2: Prisma proof

- minimum viable adapter
- ordinary CRUD happy path
- no kintone concepts in application code
- unsupported/expensive operations detected before backend failure
- `check` / `explain` evidence

ここで core contract を dogfood し、Prisma 固有 abstraction が core に漏れていないことを確認する。

### Phase 3: WordPress Core proof

- wpdb/drop-in boundary
- minimum SQL/parser adapter needed for WordPress
- WordPress Core boot/install path
- basic content CRUD
- users/options/taxonomy/comments
- postmeta strategy experiment
- compatibility diagnostics

ここで SQL-oriented consumer の要求によって core が壊れないことを検証する。

### Phase 4: compatibility tooling

- source/plugin compatibility check
- stable diagnostic codes
- plan snapshots / golden tests
- cost warnings
- schema drift check
- CI-friendly strict mode

### Phase 5: backend portability proof

kintone 以外の record-oriented datastore を 1 つ追加し、core が kintone abstraction になっていないことを確認する。

これは初期リリースの必須条件ではない。

---

## Acceptance criteria for the initial architecture

以下を満たしたら、この親構想の core architecture は成立したとみなす。

### Abstraction

- [ ] core が Prisma / WordPress / kintone の concrete API に依存しない
- [ ] consumer -> core <- backend の dependency direction が維持される
- [ ] backend constraints が Capability Manifest / planner に集約される
- [ ] diagnostics と runtime が同じ execution/capability model を利用する

### Developer experience

- [ ] ordinary CRUD では application code に kintone App ID / field code / revision / pagination details が現れない
- [ ] unsupported semantics は backend API error より前に検出できる
- [ ] expensive but valid operations は warning として区別できる
- [ ] developer が必要な場合だけ execution plan を inspect できる

### Prisma proof

- [ ] basic CRUD が ordinary ORM usage として動く
- [ ] portable filters / ordering / pagination が動く
- [ ] unsupported relation / aggregate / transaction behavior を明示的に reject できる
- [ ] kintone-specific logic が Prisma adapter に重複しない

### WordPress proof

- [ ] stock WordPress Core を fork しない
- [ ] database drop-in boundary から Hibari を利用する
- [ ] basic WordPress content operations が kintone-backed persistence で動く
- [ ] plugin/query compatibility を Native / Emulated / Expensive / Unsupported に分類できる
- [ ] arbitrary unsupported SQL を黙って誤実行しない
- [ ] WordPress-specific requirements によって Hibari core が SQL database implementation へ変質しない

---

## Architecture guardrails

実装中に次の兆候が出た場合は、先へ進む前に core boundary を再検討する。

- Prisma adapter が kintone REST endpoint を直接知っている
- WordPress adapter が kintone App ID / field code を直接操作している
- core IR が SQL syntax の都合で肥大化する
- kintone の制限値が複数 package に copy される
- unsupported operation を silent client-side fallback で再現する
- `check` と runtime で別の互換性判定ロジックを持つ
- WordPress 対応のために「完全 MySQL emulator」を作り始める

---

## First implementation issue after this one

この親 issue の次は巨大実装に入らず、次の 1 issue を切る。

**Hibari minimal contracts and capability planner**

範囲:

1. backend-neutral Schema IR
2. minimal Query / Mutation IR
3. Capability Manifest
4. Native / Emulated / Expensive / Unsupported classification
5. Execution Plan
6. stable diagnostic model
7. fake backend を使った contract tests

kintone REST API integration、Prisma、WordPress はこの child issue には含めない。

この最小 contract を固定してから kintone backend を最初の real backend として dogfood する。
