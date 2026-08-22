# Hibari WordPress taxonomy / relation projection proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`

## Goal

Stock WordPress 7.1 の category relationship を、Hibari core を SQL JOIN engine にせず、generic relation-edge capability として表現できることを実証する。

`wp_term_relationships` が要求する many-to-many edge semantics を consumer-neutral contract へ分解し、WordPress adapter が legacy SQL/high-level query shape を吸収しても core が relational SQL abstraction に変質しないことを確認する。

## Scope correction after source inspection

`wp_insert_term()` は relation edgeだけの操作ではない。Stock WordPress 7.1 Coreは term creation 中に name/slug uniqueness、`wp_terms` + `wp_term_taxonomy` JOIN、duplicate confidence check まで行う。

それらをこのchildへ混ぜると Relation Edge proof が Term entity/uniqueness/SQL JOIN compatibility proofへ拡散するため、**term creation は独立childへ分離した**。

このchildでは pre-existing category Term / TermTaxonomy を backend fixture に与え、stock relation APIだけを証明した。

## Implemented generic relation contract

WordPress table namesやtaxonomy conceptsをcoreへ入れず、backend-neutral Relation Edge contractを追加した。

```text
RelationEdgeBinding
  model
  idField
  leftField
  rightField
  optional contextField
  optional orderField

RelationEdge operation
  lookup
  attach
  detach
  replace
```

Relation operation は既存 QueryIR / MutationIR へlowerされ、既存 Capability Planner / ExecutionPlan / diagnosticsをそのまま利用する。

```text
Relation operation
      |
      v
RelationEdge lowering
      |
QueryIR / MutationIR
      |
existing Capability Planner / ExecutionPlan
```

`attach` の duplicate prevention、`replace` の current-set diff、`detach` は capability manifestにより native/emulated/unsupported として明示される。

## Kintone capability profile

このproofのkintone profile:

- left-scoped lookup: native
- pair lookup: native
- multi-edge: native
- attach: native
- uniqueAttach: emulated
- detach: emulated
- replace: emulated
- cross-owner scan: unsupported

Atomic unique edgeはclaimしない。`uniqueAttach=emulated` は existence-check + insert であることをplannerに残す。

## WordPress projection

Conceptual mapping:

```text
wp_term_taxonomy            -> TermTaxonomy entity/context
wp_term_relationships       -> RelationEdge

object_id                   -> edge.leftId
term_taxonomy_id            -> edge.rightId
term_order                  -> edge.order (optional)
```

kintone proofでは TermTaxonomy と RelationEdge を別Appへbindingするが、App ID / field codeはbackend fixture/configurationだけが知る。

### High-level `terms_pre_query` boundary

`WP_Term_Query` は通常 `terms INNER JOIN term_taxonomy` を生成し、`object_ids` があれば relationship JOINも追加する。`fields=tt_ids` でも通常は一度 term object を構成してから term_taxonomy_id を返す。

HibariはJOIN SQLをcoreへ入れず、WordPress自身の `terms_pre_query` semantic short-circuitを利用する。

Exact supported shapes:

1. `term_exists(int, taxonomy)`
   - `TermTaxonomy` を `termId + taxonomy` でbounded lookup
   - `term_id` / `term_taxonomy_id` pairをWordPressへ返す
2. `wp_get_object_terms(..., fields=tt_ids)`
   - `TermRelationship.leftId IN objectIds` をbounded lookup
   - 得たright IDsを `TermTaxonomy.id IN ... + taxonomy` でbounded context check
   - JOINなしで最終tt_idsをWordPressへ返す

Unsupported arbitrary `WP_Term_Query` は通常SQL pathへ残り、existing JOIN preflight diagnosticで拒否される。

### Narrow SQL boundary

Stock `wp_set_object_terms()` / `wp_remove_object_terms()` が直接発行する単純な `wp_term_relationships` SQLだけを `TaxonomySqlTranslator` が扱う。

- pair existence SELECT
- relation INSERT
- object + term_taxonomy_id IN (...) DELETE

Generic JOIN parser / relational engineは追加していない。

## Stock public API proof

Fixture:

- object ID: `42`
- term ID: `7`
- category term-taxonomy ID: `1`

Stock APIs unchanged:

```text
term_exists(7, "category")
wp_get_object_terms(42, "category", fields=tt_ids)
wp_set_object_terms(42, [7], "category", false)
wp_get_object_terms(...)
wp_set_object_terms(42, [7], "category", false)  # repeated attach
wp_get_object_terms(...)
wp_remove_object_terms(42, [7], "category")
wp_get_object_terms(...)
```

Observed semantics:

- pre-existing term/taxonomy context resolves to term `7` / tt `1`
- initial relation set is empty
- first set attaches tt `1`
- later public read observes `[1]`
- repeated set does not create a second edge
- remove succeeds
- later public read observes absence

Term count recomputation is deliberately deferred with stock `wp_defer_term_counting(true)` because aggregate/count maintenance is not part of this relation-edge child.

## Acceptance criteria

- [x] generic relation-edge contract が core に追加されても WordPress / SQL / kintone concrete API に依存しない
- [x] relation operation が既存 QueryIR / MutationIR と planner/diagnosticsへ lower される
- [x] exact WordPress taxonomy high-level query projection is owned by the WordPress consumer
- [x] only narrow direct `wp_term_relationships` SQL shapes are translated; generic JOIN remains unsupported
- [x] pre-existing Term / TermTaxonomy fixture is resolved without implementing term creation in this child
- [x] stock `wp_set_object_terms()` が unchanged でedgeを作る
- [x] stock public relation read がbackend stateからmembershipを観測する
- [x] same object/termの再attachでduplicate edgeを作らない
- [x] stock `wp_remove_object_terms()` がedgeを削除する
- [x] later public relation read observes absence
- [x] relation-scoped lookupがbackend full scanへsilent fallbackしない
- [x] WordPress/coreにkintone App ID / field codeを入れない
- [x] previous core/kintone/Prisma/WordPress proofs remain green

## Completion evidence

Implementation proof revision:

`b95c95f7293597683c6987e5a4d47d35416adb09` on `feature/wordpress-taxonomy-relations`.

GitHub Actions CI run #152 (`32573416099`) passed all seven jobs:

- `test`
- `wordpress-proof`
- `wordpress-runtime-proof`
- `wordpress-options-proof`
- `wordpress-post-content-proof`
- `wordpress-postmeta-proof`
- `wordpress-taxonomy-proof`

Stock WordPress 7.1 proof output:

```text
WordPress taxonomy relation edges -> Hibari -> KintoneBackend proof: ok
```

Before the final green run, the same implementation already showed the semantic projection reaching KintoneBackend with bounded requests:

```text
app 87: (Term_id = 7 and Taxonomy = "category") limit 1
app 88: Object_id in (42)
```

The final shell proof additionally asserts:

- configured TermTaxonomy app and RelationEdge app are both reached;
- taxonomy context is pushed down as `Taxonomy = "category"`;
- object-scoped relation read is pushed down as `Object_id in (42)`;
- repeated stock attach produces exactly one relation-record POST;
- persisted relation fields are `Object_id=42` and `Term_taxonomy_id=1`;
- detach reaches KintoneBackend DELETE.

Core Relation Edge contract tests and all previous core/kintone/Prisma/WordPress regression suites are green in the same run.

## Guardrails preserved

- no SQL JOIN AST in core
- no generic relational database emulator
- no taxonomy-specific relation type in core
- no JSON blob hiding relation membership
- no unsupported JOIN -> client-side unbounded scan fallback
- no WordPress -> kintone direct dependency
- no kintone App ID / field code in WordPress/core
- no `wp_insert_term()` compatibility smuggled into this child

## Deferred work

A separate child should cover Term entity creation/uniqueness, including the semantics required by stock `wp_insert_term()` without weakening the JOIN guardrail.

Other non-goals remain:

- arbitrary `WP_Term_Query`
- arbitrary taxonomy JOIN SQL
- nested hierarchical term traversal
- termmeta
- term count optimization/aggregate maintenance
- custom taxonomy/plugin compatibility
- post delete cascade
- live kintone credentials
