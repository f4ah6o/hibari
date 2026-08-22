# Hibari WordPress postmeta / dynamic attributes proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-db-dropin-boundary-proof.md`
- `issues/closed/20260822-wordpress-runtime-transport-proof.md`
- `issues/closed/20260822-wordpress-options-crud-proof.md`
- `issues/closed/20260822-wordpress-post-content-cru-proof.md`

## Goal

Phase 3 の次の設計課題として、stock WordPress 7.1 の `wp_postmeta` / EAV semantics を WordPress 専用 SQL hack にせず、Hibari の generic dynamic-attributes capability として表現できることを最小 proof で実証する。

この child の目的は plugin compatibility を広げることではない。まず Core の public metadata API が要求する意味を分解し、consumer -> core <- backend の境界を壊さず create/read/update/delete を成立させる。

## Implemented architecture

Dynamic Attributes は新しい datastore runtime / SQL engine として実装しない。

`@hibari/core` に consumer-neutral な operation/binding と capability contract を追加し、高位operationを既存 QueryIR / MutationIR に lower する。

```text
DynamicAttribute operation
  ownerId / key / value / cardinality intent
        |
        v
lowerDynamicAttributeOperation()
        |
 QueryIR / MutationIR
        |
 existing planner / ExecutionPlan / diagnostics
        |
 DatastoreRuntime
```

Core binding:

```text
DynamicAttributeBinding
  model
  idField
  ownerField
  keyField
  valueField
```

WordPress adapter は stock `wp_postmeta` SQL subset を `PostMeta` modelへ正規化する。kintone proofでは別Appをbindingするが、App ID / field code はbackend fixture/configurationだけが知る。

```text
wp_postmeta.meta_id    -> PostMeta.id
wp_postmeta.post_id    -> PostMeta.ownerId
wp_postmeta.meta_key   -> PostMeta.key
wp_postmeta.meta_value -> PostMeta.value
```

## Capability semantics

`CapabilityManifest.dynamicAttributes`:

- `ownerKeyLookup`
- `ownerKeyValueLookup`
- `multiValue`
- `uniqueAdd`
- `scan`

kintone profile in this proof:

- owner/key lookup: native
- owner/key/value lookup: native
- multi-value: native
- unique add: emulated
- unbounded cross-owner scan: unsupported

`uniqueAdd=emulated` is intentional. Stock WordPress 7.1 itself implements `unique=true` as an existence COUNT check followed by INSERT, rather than requiring an atomic database uniqueness constraint. Hibari exposes that multi-request semantic explicitly instead of pretending it is backend-native.

## WordPress SQL subset

`PostmetaSqlTranslator.php` owns only the stock SQL shapes exercised by the Metadata API proof:

- unique existence `SELECT COUNT(*) ... meta_key + post_id`
- metadata-cache `SELECT post_id, meta_key, meta_value ... post_id IN (...) ORDER BY meta_id`
- `SELECT meta_id ...` by owner/key and optional value
- INSERT owner/key/value
- UPDATE value by owner/key and optional previous value
- DELETE by selected meta IDs

The generic aggregate rejection remains in place; only the exact stock postmeta existence COUNT shape is admitted by WordPress preflight. Arbitrary aggregate/JOIN/subquery SQL remains unsupported.

## Acceptance criteria

- [x] generic dynamic-attributes contract が core に追加されても WordPress / SQL / kintone concrete API に依存しない
- [x] existing Query/Mutation/Execution Plan classification model と同じ planner/diagnosticsを利用する
- [x] WordPress adapter が必要最小限の stock `wp_postmeta` SQL shape のみを所有する
- [x] stock `add_post_meta()` が unchanged で metadata を永続化する
- [x] stock `get_post_meta()` が backend state から値を読む
- [x] stock `update_post_meta()` の変更を later read が観測する
- [x] stock `delete_post_meta()` 後の later read が metadata absence を観測する
- [x] same owner/key の multi-value semantics を最低1 contract testで証明する
- [x] `unique=true` の意味を最低1 contract testで証明する
- [x] Kintone App ID / field code が WordPress adapter/coreへ漏れない
- [x] unsupported arbitrary postmeta SQL を黙って誤実行しない
- [x] previous core/kintone/Prisma/WordPress CI proofs remain green

## Completion evidence

Implementation proof revision: `7c3a04d90533209f2dc7816c0ff31d356c84832d` on `feature/wordpress-postmeta-dynamic-attributes`.

GitHub Actions CI run #131 (`32572063954`) passed all six jobs:

- `test`
- `wordpress-proof`
- `wordpress-runtime-proof`
- `wordpress-options-proof`
- `wordpress-post-content-proof`
- `wordpress-postmeta-proof`

Core contract suite is 10/10 green. The three new contracts prove:

1. same owner/key can lower to independent insert rows and therefore preserve multi-value semantics;
2. unique-add is planned as an explicit existence-check + insert and classified as emulated when the backend says so;
3. a backend/profile without Dynamic Attributes is rejected before execution with the shared planning error model.

Kintone regression suite remains 12/12 green. Prisma SQL tests remain 6/6 green, generated Prisma Client CRUD remains green, runtime HTTP remains 3/3 green, and the Prisma -> KintoneBackend integration proof remains green.

Stock WordPress 7.1 proof output:

```text
WordPress postmeta dynamic attributes -> Hibari -> KintoneBackend proof: ok
```

The public API sequence proves:

- two `add_post_meta()` calls on the same owner/key persist `one` and `two` as independent values;
- `get_post_meta(..., false)` observes both values in insertion order;
- `add_post_meta(..., true)` accepts the first value and rejects a duplicate owner/key add;
- `update_post_meta(..., previousValue)` changes only the selected value and later read observes it;
- value-selective `delete_post_meta()` removes only the matching value;
- key-only delete removes the remaining value and later read observes absence.

The shell proof verifies the fake Kintone request log reached metadata app binding `86` with GET / POST / PUT / DELETE, saw `Meta_key=hibari_label`, independently persisted `Meta_value=one` and `Meta_value=two`, persisted `Meta_value=updated`, and observed exactly one POST for the `hibari_unique` key.

The stock metadata-cache read is unbounded at the WordPress SQL boundary. `KintoneBackend` therefore correctly selects its transparent Cursor API strategy; the fake transport now implements Add/Get/Delete Cursor as part of the backend proof rather than weakening the query or forcing a WordPress-specific limit.

## Guardrails preserved

- no `wp_postmeta` table emulator in core
- no generic SQL/EAV engine
- no single JSON blob that hides multi-value/cardinality semantics
- no WordPress -> kintone direct dependency
- no kintone App ID / field code in WordPress or core
- no silent full-scan fallback
- existing QueryIR / MutationIR / ExecutionPlan / diagnostics remain the single runtime/planning model

## Non-goals

- arbitrary plugin postmeta SQL
- `WP_Meta_Query` general compatibility
- cross-post metadata search
- optimized indexes/materialized fields
- taxonomy/comments/users/media metadata
- post deletion cascade
- live kintone credentials
