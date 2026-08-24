# Hibari WordPress post force-delete taxonomy cascade proof

## Status

Open — implementation `9319b69f5f73abe3d0e8c29c4ecddafb491ebed0` under full-CI verification.

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-taxonomy-relation-projection-proof.md`
- `issues/closed/20260822-wordpress-term-entity-creation-uniqueness-proof.md`
- `issues/closed/20260823-wordpress-tag-taxonomy-proof.md`
- `issues/closed/20260823-wordpress-page-force-delete-cascade-proof.md`

## Goal

親issue Phase 3 の `post CRUD` Delete を閉じるため、stock WordPress 7.1 の通常 `post` に category/tag Relation Edges を持たせた状態で `wp_delete_post($id, true)` を実行し、`wp_delete_object_term_relationships()` が既存 Term / TermTaxonomy / Relation Edge contractsだけで関係を掃除できることを実証する。

Page force-delete childで Post / Comment / Dynamic Attributes cascade lifecycleは証明済み。このchildでは新しいcascade primitiveを追加せず、通常postに固有のtaxonomy relationship cleanupだけを追加evidenceとする。

## Design hypothesis

Stock WordPress Core already owns the semantic lifecycle:

```text
wp_delete_post(post, true)
  -> wp_delete_object_term_relationships(post_id, taxonomies)
       -> wp_get_object_terms(post_id, taxonomy, fields=ids)
       -> wp_remove_object_terms(post_id, term_ids, taxonomy)
  -> existing Post/Comment/PostMeta delete lifecycle
```

Hibari should only project the object-scoped `fields=ids` term read through existing bounded contracts:

```text
Relation Edge(leftId=post)
  -> rightId = TermTaxonomy.id
  -> TermTaxonomy(termId, taxonomy)
  -> WordPress term IDs
```

Then existing `wp_remove_object_terms()` / Relation Edge delete semantics should perform the actual edge deletion.

## First proof scope

Use unchanged stock public APIs:

```text
wp_insert_post(post, draft)
wp_insert_term(..., category)
wp_insert_term(..., post_tag)
wp_set_object_terms(post, category)
wp_set_object_terms(post, post_tag)
wp_get_object_terms(..., fields=ids)
wp_delete_post(post_id, true)
wp_get_object_terms(..., fields=ids)
get_post(post_id)
```

Verify:

- one category and one tag context exist
- one Relation Edge per taxonomy is attached to the Post
- `fields=ids` reads the term IDs through bounded semantic projection without generic JOIN execution
- stock `wp_delete_post()` invokes relationship cleanup
- actual Relation Edge DELETE requests remove both category and tag memberships
- final object-scoped taxonomy reads observe no membership
- final Post read observes absence
- Term and TermTaxonomy records themselves remain; deleting a post removes membership, not terms

## Compatibility boundary

Extend only the existing high-level `terms_pre_query` projection for object-scoped `fields=ids` using Relation Edge + TermTaxonomy bounded queries.

Do not add generic `WP_Term_Query`, JOIN execution, cross-object term scan, or a WordPress-specific cascade primitive to core.

## Acceptance criteria

- [ ] stock `wp_delete_post($id, true)` is unchanged
- [ ] existing Relation Edge contract owns membership deletion
- [ ] object-scoped `wp_get_object_terms(..., fields=ids)` is projected through bounded existing IR
- [ ] category edge is removed
- [ ] post_tag edge is removed
- [ ] final taxonomy reads observe no membership
- [ ] Term / TermTaxonomy entities remain intact
- [ ] final Post read observes absence
- [ ] no generic JOIN / WP_Term_Query engine is enabled
- [ ] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- no new taxonomy entity type in core
- no WordPress cascade primitive in core
- no fixture-side fake relationship cleanup
- no term deletion
- no term-count aggregation claim
- no revision semantics claim
- no live kintone credentials

## Completion evidence required

- exact stock WordPress 7.1 public API output
- fake Kintone evidence for category/tag edge attach and DELETE
- evidence that Term/TermTaxonomy rows survive Post deletion
- full CI including all previous proofs
- exact final revision/run recorded before moving this issue to `issues/closed`
