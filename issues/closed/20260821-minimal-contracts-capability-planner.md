# Hibari minimal contracts and capability planner

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Goal

Hibari の最初の実装単位として、consumer / backend の concrete API に依存しない最小 contract と capability planner を固定する。

この issue では kintone REST API、Prisma、WordPress を実装しない。fake backend capability manifest を使って、同じ planner が runtime 前の compatibility 判定と inspectable execution plan の両方に使えることを contract test で証明する。

## Scope

- backend-neutral Schema IR
- minimal Query IR
  - projection
  - scalar filter: eq / ne / gt / gte / lt / lte / in / and / or
  - ordering
  - limit
  - cursor
  - offset
- minimal Mutation IR
  - insert
  - insert many
  - update
  - update many
  - delete
  - upsert
  - optimistic concurrency condition
- Capability Manifest
- `native` / `emulated` / `expensive` / `unsupported` classification
- inspectable Execution Plan
- stable diagnostic model / core diagnostic codes
- `assertExecutable` boundary for pre-backend rejection
- fake backend contract tests

## Design constraints

- core に kintone App ID / field code / REST endpoint を入れない
- core に Prisma / SQL AST / wpdb concepts を入れない
- backend limits は Capability Manifest を single source of truth とする
- transparent batching / pagination は backend detail として吸収可能にする
- emulation と expensive warning と unsupported error を別分類にする
- unsupported semantics は backend execution より前に reject 可能にする
- diagnostics と execution planning で別々の compatibility logic を持たない

## Acceptance criteria

- [x] Schema IR が identifier / uniqueness / concurrency token / embedded collection / relation hint / extension metadata を表現できる
- [x] Query / Mutation IR が親 issue の最小 portable operation を表現できる
- [x] fake backend manifest だけで operation compatibility を計画できる
- [x] native query が warning なしで plan できる
- [x] emulated capability を info として plan に残せる
- [x] expensive operation を warning として plan に残せる
- [x] unsupported operation を stable diagnostic code 付きで backend execution 前に reject できる
- [x] query と mutation が同一の classification / diagnostics model を利用する
- [x] pagination / batching の estimated request count を inspect できる
- [x] contract tests が green

## Completion evidence

完了。

実装:

- `src/schema.ts`: backend-neutral Schema IR
- `src/query.ts`: minimal Query IR / portable scalar filters
- `src/mutation.ts`: minimal Mutation IR / optimistic concurrency condition
- `src/capabilities.ts`: Capability Manifest / backend limits
- `src/plan.ts`: Execution Plan / `assertExecutable` / `HibariPlanningError`
- `src/diagnostics.ts`: stable diagnostic codes and structured diagnostic contract
- `src/planner.ts`: shared query/mutation capability planner
- `test/planner.test.mjs`: fake backend capability contract tests

Diagnostics は `code / severity / operation / target / reason / capability / optional hint / optional source location` を同一 contract で保持する。

Planner は次を区別する。

- `native`: backend にそのまま委譲可能
- `emulated`: Hibari が意味を保って補完
- `expensive`: 意味は保てるが backend limit / request cost に近い
- `unsupported`: semantic equivalence を保証できず実行前 reject

検証:

```text
$ npm test

1..7
# tests 7
# pass 7
# fail 0
```

contract suite は native query、emulated filter、expensive offset、hard offset limit、unsupported filter、mutation 共通判定、transparent batching + request-cost warning を確認した。

kintone REST API、Prisma、WordPress の concrete API は core に導入していない。
