# Hibari WordPress term entity creation / uniqueness proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisite

- `issues/closed/20260822-wordpress-taxonomy-relation-projection-proof.md`

## Goal

Stock WordPress 7.1 の `wp_insert_term()` を、Hibari core に SQL JOIN engine や WordPress-specific Term abstractionを追加せず、既存 SchemaIR / QueryIR / MutationIR と WordPress consumerの bounded semantic projectionだけで成立させる。

前childで Relation Edge membershipは証明済み。このchildは **Term entity identity + TermTaxonomy context + WordPress name/slug uniqueness semantics** に限定する。

## Implemented design

新しいcore contractは追加しなかった。

- `Term` / `TermTaxonomy` は普通のbackend-neutral modelとして既存QueryIR / MutationIRで扱う
- `terms_pre_query` で stock WordPress が要求する exact term lookup を semantic projectionする
- `wp_insert_term()` 固有の2種類のJOINだけをWordPress adapter内で複数のbounded QueryIRへ分解する
- generic JOIN executionは追加せず、それ以外は既存 `HIB-WP-JOIN-*` でrejectする
- hierarchical taxonomy cache regenerationの exact `fields=id=>parent` queryは、taxonomy-scoped `TermTaxonomy(termId,parent)` projectionとして処理する
- stock WordPress 7.1 の `wp_load_alloptions()` が必要とする exact autoload preloadもconsumer側でQueryIRへ投影する
- cross-App atomic uniquenessはclaimしない

Conceptual mapping:

```text
wp_terms
  term_id      -> Term.id
  name         -> Term.name
  slug         -> Term.slug
  term_group   -> Term.group

wp_term_taxonomy
  term_taxonomy_id -> TermTaxonomy.id
  term_id          -> TermTaxonomy.termId
  taxonomy         -> TermTaxonomy.taxonomy
  description      -> TermTaxonomy.description
  parent           -> TermTaxonomy.parent
  count            -> TermTaxonomy.count
```

## Proven stock semantics

Stock public APIs, unchanged:

```text
wp_insert_term("Hibari Category", "category")
term_exists(createdTermId, "category")
get_term_by("slug", "hibari-category", "category")
wp_insert_term("Hibari Category", "category")  # duplicate
```

Proven behavior:

- first insert returns `term_id` and `term_taxonomy_id`
- created Term persists name=`Hibari Category`, slug=`hibari-category`
- created TermTaxonomy persists taxonomy=`category`, parent=0, count=0
- `term_exists()` resolves the created pair from backend state
- slug lookup resolves the created `WP_Term`
- second insert returns `WP_Error('term_exists', ...)`
- duplicate attempt causes no second Term or TermTaxonomy write

## Acceptance criteria

- [x] no new WordPress/SQL/kintone-specific concept is added to Hibari core
- [x] existing QueryIR / MutationIR / planner/runtime remain the execution model
- [x] Term and TermTaxonomy are ordinary backend-neutral models
- [x] exact WordPress term-query shapes use semantic projection before JOIN SQL execution
- [x] only exact direct SQL shapes required by stock `wp_insert_term()` are admitted
- [x] arbitrary JOIN remains rejected by existing `HIB-WP-JOIN-*` behavior
- [x] first stock `wp_insert_term()` creates Term + TermTaxonomy through KintoneBackend
- [x] later `term_exists()` observes created backend state
- [x] duplicate stock `wp_insert_term()` returns `term_exists` error
- [x] duplicate attempt creates no second logical Term/TermTaxonomy pair
- [x] WordPress/core contain no kintone App ID / field code
- [x] previous core/kintone/Prisma/WordPress proofs remain green

## Completion evidence

Implementation proof revision before documentation close:

- branch revision: `5ca1350ae8b25d24af3c8e2f9e6467fa50606e4c`
- GitHub Actions: CI run `#165`, run id `32576194218`
- all 8 jobs green, including all previous proofs
- `wordpress-term-creation-proof` output:
  - `WordPress term creation + uniqueness -> Hibari -> KintoneBackend proof: ok`

The proof script additionally asserts from the fake Kintone request log:

- exactly one `POST /k/v1/record.json` reaches the configured Term app
- exactly one `POST /k/v1/record.json` reaches the configured TermTaxonomy app
- `Term_name = Hibari Category` and `Slug = hibari-category` are persisted
- `Taxonomy = category`, `Parent = 0`, `Count = 0` are persisted
- slug uniqueness lookup is pushed down as a Term query
- taxonomy context lookup is pushed down as a TermTaxonomy query
- re-running the duplicate public API does not create a second logical pair

A later CI run on the documentation-close revision is required before merge; this completion record intentionally distinguishes semantic proof revision from final merge-candidate verification.

## Guardrails retained

- no generic SQL JOIN AST/executor
- no WordPress Term type in core
- no taxonomy JSON-blob shortcut
- no claim of atomic cross-App uniqueness
- no arbitrary `WP_Term_Query` support
- no alias/group semantics
- no non-root hierarchy creation/traversal proof
- no termmeta or term-count recomputation proof
- no weakening of unsupported SQL diagnostics

## Non-goals

- arbitrary `WP_Term_Query`
- aliases / `term_group`
- non-root hierarchical parent creation
- termmeta
- term count maintenance
- taxonomy relationship membership (already proven separately)
- custom taxonomy/plugin compatibility
- live kintone credentials
