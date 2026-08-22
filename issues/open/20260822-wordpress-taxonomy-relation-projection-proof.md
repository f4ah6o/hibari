# Hibari WordPress taxonomy / relation projection proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`

## Goal

Stock WordPress 7.1 の category/tag relationship を、Hibari core を SQL JOIN engine にせず、generic relation-edge capability として表現できることを実証する。

この child の主目的は taxonomy feature 数を増やすことではない。`wp_terms` / `wp_term_taxonomy` / `wp_term_relationships` が要求する entity + context + many-to-many edge を consumer-neutral contract へ分解し、WordPress adapter が SQL-oriented legacy shape を吸収しても core が relational SQL abstraction に変質しないことを確認する。

## First proof scope

Stock public API を使う。

- `wp_insert_term()` で1 categoryを作る
- `wp_set_object_terms()` で既存 draft post/page と category を関連付ける
- `wp_get_object_terms()` または `get_the_terms()` で関係を読む
- relationship の重複追加はduplicate edgeを作らない
- `wp_remove_object_terms()` でedgeを削除し、later readがabsenceを観測する

最初のproofは hierarchical category 1種類、parent=0、term metadataなし、term orderなしに限定する。

## Generic relation contract

WordPress table namesをcoreへ入れない。

概念的には次を表現する。

```text
RelationEdgeBinding
  model
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

概念 mapping:

```text
wp_terms                    -> Term entity
wp_term_taxonomy            -> TermTaxonomy entity/context
wp_term_relationships       -> RelationEdge

object_id                   -> edge.leftId
term_taxonomy_id            -> edge.rightId
term_order                  -> edge.order (optional)
```

kintone proofでは各entity/edgeを別Appへbindingしてよいが、App ID/field codeはbackend configuration以外へ漏らさない。

## Important WordPress semantics

Stock `wp_set_object_terms()` は少なくとも次を行う。

- current relation setを読む
- term existence / taxonomy contextを確認する
- missing edgeのみinsertする
- append=falseではold/new差分を削除する
- relationship duplicateを作らない

この意味を「SQL JOINがないから全scan」で黙って再現しない。bounded owner-scoped edge lookupをportable capabilityとして扱う。

## Capability requirements

Generic relation-edge capabilitiesとして最低限:

- left-scoped lookup
- left+right existence lookup
- multi-edge
- unique edge / duplicate prevention
- attach
- detach
- replace/diff emulation
- cross-owner scan cost

kintoneでatomic unique edgeを保証できない場合は `uniqueAttach=emulated` として存在確認+insertを明示する。競合を完全に防げないならその制約をdiagnostic/planに残す。

## Acceptance criteria

- [ ] generic relation-edge contract が core に追加されても WordPress / SQL / kintone concrete API に依存しない
- [ ] relation operation が既存 QueryIR / MutationIR と planner/diagnosticsへ lower される
- [ ] WordPress adapter が必要最小限の stock taxonomy SQL shape のみを所有する
- [ ] stock WordPress 7.1 `wp_insert_term()` が unchanged で term/contextを永続化する
- [ ] stock `wp_set_object_terms()` が unchanged でedgeを作る
- [ ] later public read がedge/termをbackend stateから観測する
- [ ] same object/termの再attachでduplicate edgeを作らない
- [ ] stock `wp_remove_object_terms()` がedgeを削除する
- [ ] relation-scoped lookupがbackend full scanへsilent fallbackしない
- [ ] arbitrary JOIN SQLは引き続きunsupportedである
- [ ] WordPress/coreにkintone App ID / field codeを入れない
- [ ] previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- coreにSQL JOIN ASTを入れない
- generic relational database emulatorを作らない
- taxonomy専用relation typeをcoreへ入れない
- relation setを単一JSON blobへ隠さない
- unsupported JOINをclient-side unbounded scanで再現しない
- comments/users/media/revisionsをこのchildへ混ぜない
- termmetaは既存Dynamic Attributesを別child/extensionで再利用可能とするが、このproofの必須範囲にしない

## Completion evidence required

- relation-edge planner/lowering contract tests
- stock WordPress 7.1 public taxonomy API proof
- fake Kintone request log for term/context/edge create/read/delete
- duplicate-edge behavior evidence
- full CI with all previous proofs green
- exact run/revisionをissueへ記録してからclosedへ移動する

## Non-goals

- arbitrary `WP_Term_Query`
- arbitrary taxonomy JOIN SQL
- tag + categoryを同時に網羅すること
- nested hierarchical term traversal
- termmeta
- term count optimization beyond semantics required by the proof
- custom taxonomy/plugin compatibility
- post delete cascade
- live kintone credentials
