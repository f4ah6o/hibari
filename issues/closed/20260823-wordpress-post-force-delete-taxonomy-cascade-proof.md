# Hibari WordPress post force-delete taxonomy cascade proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-taxonomy-relation-projection-proof.md`
- `issues/closed/20260822-wordpress-term-entity-creation-uniqueness-proof.md`
- `issues/closed/20260823-wordpress-tag-taxonomy-proof.md`
- `issues/closed/20260823-wordpress-page-force-delete-cascade-proof.md`

## Goal

親issue Phase 3 の `post CRUD` Delete を閉じるため、stock WordPress 7.1 の通常 `post` に category/tag Relation Edges を持たせた状態で `wp_delete_post($id, true)` を実行し、`wp_delete_object_term_relationships()` が既存 Term / TermTaxonomy / Relation Edge contractsだけで関係を掃除できることを実証した。

Page force-delete childで証明済みの Post lifecycle はそのまま再利用し、このchildでは通常postに固有のtaxonomy relationship cleanupだけを追加evidenceとした。core に WordPress-specific cascade primitive は追加していない。

## Proven public flow

Unchanged stock WordPress 7.1 APIs:

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
term_exists(category)
term_exists(post_tag)
```

Observed semantic lifecycle:

```text
wp_delete_post(post, true)
  -> wp_delete_object_term_relationships(post_id, taxonomies)
       -> wp_get_object_terms(post_id, taxonomy, fields=ids)
            -> TermRelationship(leftId=post)
            -> TermTaxonomy(id, termId, taxonomy)
            -> WordPress term IDs
       -> wp_remove_object_terms(post_id, term_ids, taxonomy)
            -> existing TermRelationship delete MutationIR
  -> existing Post delete lifecycle
```

## Narrow consumer addition

`TaxonomyObjectTermProjection` was added at the WordPress consumer boundary. It handles only the exact object-scoped `fields=ids` semantic seam exposed by `terms_pre_query` and lowers it to bounded existing IR:

- query `TermRelationship` by object identity
- query `TermTaxonomy` by returned context IDs plus one taxonomy
- return Term IDs in relation order

It does not add generic `WP_Term_Query`, JOIN execution, cross-object term scans, a new taxonomy entity type, or a WordPress-specific core contract.

The SHORTINIT proof harness also loads stock `wp-includes/post-formats.php`, because normal `post` insertion installs the stock `_post_format_get_terms()` filter. The first verification run exposed this missing harness dependency; no production/runtime abstraction change was required.

## Acceptance criteria

- [x] stock `wp_delete_post($id, true)` is unchanged
- [x] existing Relation Edge contract owns membership deletion
- [x] object-scoped `wp_get_object_terms(..., fields=ids)` is projected through bounded existing IR
- [x] category edge is removed
- [x] post_tag edge is removed
- [x] final taxonomy reads observe no membership
- [x] Term / TermTaxonomy entities remain intact
- [x] final Post read observes absence
- [x] no generic JOIN / WP_Term_Query engine is enabled
- [x] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails preserved

- no new taxonomy entity type in core
- no WordPress cascade primitive in core
- no fixture-side fake relationship cleanup
- no term deletion
- no term-count aggregation claim
- no revision semantics claim
- no live kintone credentials

## Completion evidence

- implementation revision: `9319b69f5f73abe3d0e8c29c4ecddafb491ebed0`
- focused harness fix revision: `90d35b75fdcef8048172c9f49c6a0bc391ab02eb`
- PR verification merge-test revision: `fe6ae308064802f66335dc66ea268e2e42096c74`
- PR: #20
- final verification CI: run #249 / `32688442529`: success, 16/16 jobs green
- focused job: `wordpress-post-taxonomy-delete-proof` / `97317665719`: success
- exact stock proof output:
  - `WordPress post taxonomy force-delete cascade -> Hibari -> KintoneBackend proof: ok`
- fake Kintone evidence confirms:
  - app 85 creates exactly one Post
  - app 89 creates exactly two Term records
  - app 87 creates exactly two TermTaxonomy records, one `category` and one `post_tag`
  - app 88 creates exactly two Relation Edge records
  - object-scoped Relation Edge queries use Post identity 1
  - category/post_tag context reads are bounded by taxonomy
  - app 88 receives actual DELETE requests for Relation Edge identities 1 and 2 during stock `wp_delete_post()`
  - app 85 receives the final actual DELETE for Post identity 1
  - app 87 receives no DELETE, so TermTaxonomy contexts survive
  - app 89 receives no DELETE, so Term entities survive
  - later stock public taxonomy reads observe no membership
  - later `get_post()` observes absence
  - later `term_exists()` observes both terms and their original TermTaxonomy identities
- the fake Kintone runtime performs no hidden relationship cascade; edges disappear only after actual Hibari/Kintone DELETE requests
- verification run #248 / `32688355469` initially failed only because the SHORTINIT harness omitted stock `wp-includes/post-formats.php`; revision `90d35b7` corrected that dependency and run #249 passed
- `npm install` continues to report the pre-existing 3 high severity vulnerabilities; they are unrelated to this proof
