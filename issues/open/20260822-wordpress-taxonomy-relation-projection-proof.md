# Hibari WordPress taxonomy / relation projection proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`

## Goal

Stock WordPress 7.1 の category relationship を、Hibari core を SQL JOIN engine にせず、generic relation-edge capability として表現できることを実証する。

この child の主目的は taxonomy feature 数を増やすことではない。`wp_term_relationships` が要求する many-to-many edge semantics を consumer-neutral contract へ分解し、WordPress adapter が legacy SQL/high-level query shape を吸収しても core が relational SQL abstraction に変質しないことを確認する。

## Scope correction after source inspection

`wp_insert_term()` は relation edgeだけの操作ではない。Stock WordPress 7.1 Coreは term creation 中に name/slug uniqueness、`wp_terms` + `wp_term_taxonomy` JOIN、duplicate confidence check まで行う。

それらをこのchildへ混ぜると Relation Edge proof が Term entity/uniqueness/SQL JOIN compatibility proofへ拡散するため、**term creation は独立childへ分離する**。

このchildでは pre-existing category Term / TermTaxonomy を backend fixture に与える。WordPress adapterはその既存termを高位の `terms_pre_query` projectionで取得できるようにする。これはtaxonomy SQL JOINを一般化せず、WordPressが提供する query short-circuit boundaryを利用する。

## First proof scope

Stock public API を使う。

- backend fixtureに既存 category Term / TermTaxonomy を1件用意する
- stock `wp_set_object_terms()` で既存 draft page と category を関連付ける
- stock `wp_get_object_terms(..., fields=tt_ids)` でrelationを読む
- same object/termの再attachはduplicate edgeを作らない
- stock `wp_remove_object_terms()` でedgeを削除し、later public readがabsenceを観測する

最初のproofは hierarchical category 1種類、parent=0、term metadataなし、term orderなしに限定する。Term entity作成/uniquenessはこのproofでは実装しない。

## Generic relation contract

WordPress table namesをcoreへ入れない。

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

relation semantics は entity table JOIN ではなく、edge record の query/mutation として既存 QueryIR / MutationIR へ lower する。

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

`attach` の duplicate prevention、`replace` の current-set diff、`detach` は native/emulated/expensive/unsupported としてmanifest/plannerで明示する。

## WordPress projection

Conceptual mapping:

```text
wp_terms                    -> Term entity (pre-existing fixture in this child)
wp_term_taxonomy            -> TermTaxonomy entity/context (pre-existing fixture)
wp_term_relationships       -> RelationEdge

object_id                   -> edge.leftId
term_taxonomy_id            -> edge.rightId
term_order                  -> edge.order (optional)
```

kintone proofでは各entity/edgeを別Appへbindingしてよいが、App ID/field codeはbackend configuration以外へ漏らさない。

### High-level term query boundary

`WP_Term_Query` always generates `terms INNER JOIN term_taxonomy` and adds a third relationship JOIN when `object_ids` is present. Even `fields=tt_ids` first constructs `WP_Term` objects and only then formats `term_taxonomy_id`.

Therefore the WordPress consumer adapter must not teach Hibari core JOIN semantics. Instead it may register `terms_pre_query` and, for the exact supported taxonomy query shapes, execute bounded Hibari IR operations:

- term existence by known term/taxonomy -> bounded Term/TermTaxonomy lookup
- object relation read -> left-scoped RelationEdge lookup plus bounded term/context projection as needed

Arbitrary `WP_Term_Query` stays unsupported.

### SQL boundary that remains

Stock `wp_set_object_terms()` / `wp_remove_object_terms()` directly issue simple `wp_term_relationships` SQL for:

- pair existence lookup
- relationship insert
- relationship delete

Only these narrow edge-row SQL shapes are translated to RelationEdge QueryIR / MutationIR. Generic JOIN SQL remains rejected by preflight.

## Important WordPress semantics

Stock `wp_set_object_terms()`:

- reads current relation set when append=false
- resolves the existing term/taxonomy context
- inserts only a missing edge
- append=false removes old/new differences
- does not create duplicate relationship rows

This meaning must not be reproduced by an unbounded client-side scan. Owner-scoped edge lookup is the portable capability.

Term count maintenance is explicitly deferred in this proof because count optimization/aggregate semantics are a separate concern; the relation edge itself remains authoritative for membership.

## Capability requirements

Generic relation-edge capabilities:

- left-scoped lookup
- left+right pair lookup
- multi-edge
- unique attach / duplicate prevention
- attach
- detach
- replace/diff emulation
- cross-owner scan

kintone profile for this proof:

- left-scoped lookup: native
- pair lookup: native
- multi-edge: native
- attach: native
- uniqueAttach: emulated
- detach: emulated
- replace: emulated
- cross-owner scan: unsupported

Atomic unique edge is not claimed. `uniqueAttach=emulated` exposes existence-check + insert explicitly.

## Acceptance criteria

- [ ] generic relation-edge contract が core に追加されても WordPress / SQL / kintone concrete API に依存しない
- [ ] relation operation が既存 QueryIR / MutationIR と planner/diagnosticsへ lower される
- [ ] exact WordPress taxonomy high-level query projection is owned by the WordPress consumer
- [ ] only narrow direct `wp_term_relationships` SQL shapes are translated; generic JOIN remains unsupported
- [ ] pre-existing Term / TermTaxonomy fixture is resolved without implementing term creation in this child
- [ ] stock `wp_set_object_terms()` が unchanged でedgeを作る
- [ ] stock public relation read がbackend stateからmembershipを観測する
- [ ] same object/termの再attachでduplicate edgeを作らない
- [ ] stock `wp_remove_object_terms()` がedgeを削除する
- [ ] later public relation read observes absence
- [ ] relation-scoped lookupがbackend full scanへsilent fallbackしない
- [ ] WordPress/coreにkintone App ID / field codeを入れない
- [ ] previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- coreにSQL JOIN ASTを入れない
- generic relational database emulatorを作らない
- taxonomy専用relation typeをcoreへ入れない
- relation setを単一JSON blobへ隠さない
- unsupported JOINをclient-side unbounded scanで再現しない
- `wp_insert_term()` compatibilityをこのchildへ戻さない
- comments/users/media/revisionsをこのchildへ混ぜない
- termmetaは既存Dynamic Attributesを将来再利用する

## Completion evidence required

- relation-edge planner/lowering contract tests
- stock WordPress 7.1 public relation API proof
- fake Kintone request log for edge create/read/delete
- duplicate-edge behavior evidence
- proof that generic JOIN diagnostic remains active
- full CI with all previous proofs green
- exact run/revisionをissueへ記録してからclosedへ移動する

## Non-goals

- `wp_insert_term()` / term entity creation / name+slug uniqueness
- arbitrary `WP_Term_Query`
- arbitrary taxonomy JOIN SQL
- tag + categoryを同時に網羅すること
- nested hierarchical term traversal
- termmeta
- term count optimization/aggregate maintenance
- custom taxonomy/plugin compatibility
- post delete cascade
- live kintone credentials
