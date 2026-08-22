# Hibari WordPress postmeta / dynamic attributes proof

## Status

Open

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

## First proof scope

Stock public APIs:

- `add_post_meta()`
- `get_post_meta()`
- `update_post_meta()`
- `delete_post_meta()`

対象は既存の draft page 1件と scalar metadata key/value のみとする。

## Architecture question to settle

WordPress の EAV row を core の SQL/table semantics として導入しない。

最小 contract は概念的に次を表現する。

```text
DynamicAttributes
  owner model
  owner identity
  attribute key
  zero-or-more values
  value encoding
  query capability
  uniqueness/cardinality capability
```

WordPress adapter は `wp_postmeta` SQL shape をこの consumer-neutral contract へ正規化する。

kintone backend は最初の proof storage として separate metadata app を利用してよいが、その App ID / field code / REST details は backend binding に閉じ込める。

概念 mapping:

```text
wp_postmeta.post_id    -> DynamicAttribute.ownerId
wp_postmeta.meta_key   -> DynamicAttribute.key
wp_postmeta.meta_value -> DynamicAttribute.value
meta_id                -> DynamicAttribute.id
```

## Semantic requirements

WordPress metadata は単純な `Map<string,string>` ではない。

最低限、次を壊さないこと。

- 同一 owner/key に複数 value を持てる
- `unique=true` の add は既存 key を考慮する
- update は WordPress Core が要求する selector/cardinality semantics を維持する
- delete は key/value 条件を区別する
- PHP serialization/storage encoding は WordPress consumer boundary の concern とする
- backend が native に保証できない uniqueness/query semantics は planner/diagnostic で明示する

## Capability / diagnostics

Generic capability manifest/planner で少なくとも以下を分類可能にする。

- owner+key lookup
- owner+key+value lookup
- multi-valued attribute
- unique add / uniqueness check
- attribute scan/query cost

必要な capability が backend で意味を保てない場合は request 前に `unsupported` とする。正しいが複数 request / scan が必要なら `expensive` として plan に残す。

WordPress adapter 固有の unsupported SQL shape は stable `HIB-WP-*` diagnostic を返し、silent full-scan fallback はしない。

## Acceptance criteria

- [ ] generic dynamic-attributes contract が core に追加されても WordPress / SQL / kintone concrete API に依存しない
- [ ] existing Query/Mutation/Execution Plan classification model と同じ planner/diagnosticsを利用する
- [ ] WordPress adapter が必要最小限の stock `wp_postmeta` SQL shape のみを所有する
- [ ] stock `add_post_meta()` が unchanged で metadata を永続化する
- [ ] stock `get_post_meta()` が backend state から値を読む
- [ ] stock `update_post_meta()` の変更を later read が観測する
- [ ] stock `delete_post_meta()` 後の later read が metadata absence を観測する
- [ ] same owner/key の multi-value semantics を最低1 contract testで証明する
- [ ] `unique=true` の意味を最低1 contract testで証明する
- [ ] Kintone App ID / field code が WordPress adapter/coreへ漏れない
- [ ] unsupported arbitrary postmeta SQL を黙って誤実行しない
- [ ] previous core/kintone/Prisma/WordPress CI proofs remain green

## Guardrails

- `wp_postmeta` table emulator を core に作らない
- generic SQL/EAV engine を作らない
- metadata を単一 JSON blob にして query/cardinality semantics を隠さない
- WordPress adapter から kintone REST を直接呼ばない
- plugin-defined arbitrary SQL をこの child のために拡張しない
- taxonomy/comments/users/media/revisions を巻き込まない
- post delete cascade はまだ有効化しない

## Completion evidence required

- public API CR(U)D proof output
- fake Kintone REST request log showing metadata persistence and deletion
- planner/diagnostic contract tests for dynamic attributes
- multi-value + unique-add semantics tests
- full CI run with all existing proofs green
- issue に exact CI run/revision を記録してから closed へ移動する

## Non-goals

- arbitrary plugin postmeta SQL
- `WP_Meta_Query` general compatibility
- cross-post metadata search
- optimized indexes/materialized fields
- taxonomy/comments/users/media metadata
- post deletion cascade
- live kintone credentials
