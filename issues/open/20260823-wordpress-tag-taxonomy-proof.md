# Hibari WordPress tag taxonomy proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-taxonomy-relation-projection-proof.md`
- `issues/closed/20260822-wordpress-term-entity-creation-uniqueness-proof.md`
- `issues/closed/20260823-wordpress-draft-publish-state-proof.md`

## Goal

親issueの Initial WordPress target にある `categories / tags` のうち、既にcategoryで実証した Term / TermTaxonomy / Relation Edge contracts が `post_tag` にもそのまま適用でき、category-specific implementationではないことを stock WordPress 7.1 で実証する。

新しい Tag model や taxonomy-specific core contract は追加しない。

## Design hypothesis

```text
WordPress Term
  -> existing Term model

WordPress TermTaxonomy(taxonomy = post_tag)
  -> existing TermTaxonomy model

object <-> tag membership
  -> existing Relation Edge contract
```

`category` / `post_tag` は WordPress consumer のtaxonomy scalar/contextであり、Hibari coreのentity typeにはしない。

## First proof scope

Use unchanged stock public APIs:

```text
wp_insert_term("Hibari Tag", "post_tag")
term_exists(..., "post_tag")
get_term_by("slug", ..., "post_tag")
wp_set_object_terms(42, [$term_id], "post_tag")
wp_get_object_terms(42, "post_tag", ["fields" => "tt_ids"])
wp_remove_object_terms(42, [$term_id], "post_tag")
wp_get_object_terms(...)
```

Use deterministic object ID `42`; this proof does not mutate the Post entity itself.

Verify:

- one Term row is created
- one TermTaxonomy row with `taxonomy = post_tag` is created
- term lookup by ID/slug/taxonomy works
- one Relation Edge is attached to object 42
- repeated read observes the tag membership
- detach removes the edge
- final read observes no membership
- category remains unaffected and no category-specific contract is required

## Compatibility boundary

Reuse the semantic `terms_pre_query` projection already introduced to avoid arbitrary `WP_Term_Query` JOIN execution for the proven taxonomy contexts.

Do not enable generic JOIN execution. If stock `post_tag` emits a new shape, add only the narrow semantic/SQL projection needed for the same existing contracts.

## Acceptance criteria

- [ ] no Tag-specific concept is added to Hibari core
- [ ] Term / TermTaxonomy contracts are reused unchanged
- [ ] Relation Edge contract is reused unchanged
- [ ] stock `wp_insert_term(..., post_tag)` creates through KintoneBackend
- [ ] stock term lookup observes the tag
- [ ] stock `wp_set_object_terms()` attaches the tag through Relation Edge
- [ ] stock `wp_get_object_terms()` observes membership
- [ ] stock `wp_remove_object_terms()` detaches membership
- [ ] arbitrary JOIN SQL remains unsupported
- [ ] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not add Tag / Category entity types to core
- do not fork taxonomy logic per taxonomy name unless WordPress semantics genuinely differ
- do not add generic JOIN execution
- do not add term count aggregation for this child
- do not require a live Post row for object ID 42
- do not require live kintone credentials

## Completion evidence required

- stock WordPress 7.1 tag public API output
- fake Kintone evidence for Term / TermTaxonomy create and Relation Edge attach/detach
- proof that `taxonomy = post_tag` stays a scalar/context rather than a new core type
- full CI including every previous proof
- exact revision/run recorded before moving to `issues/closed`
