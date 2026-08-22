# Hibari WordPress term entity creation / uniqueness proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisite

- `issues/closed/20260822-wordpress-taxonomy-relation-projection-proof.md`

## Goal

Stock WordPress 7.1 の `wp_insert_term()` を、Hibari core に SQL JOIN engine や WordPress-specific Term abstractionを追加せず、既存 SchemaIR / QueryIR / MutationIR と WordPress consumerの bounded semantic projectionだけで成立させる。

前childで Relation Edge membershipは証明済み。このchildは **Term entity identity + TermTaxonomy context + WordPress name/slug uniqueness semantics** に限定する。

## Design hypothesis

新しいcore contractを先に追加しない。

Hibari coreにはすでに:

- multi-field `UniqueConstraint`
- scalar QueryIR (`eq/ne/lt/lte/gt/gte/in/and/or`)
- insert/update/delete MutationIR
- Capability Planner / ExecutionPlan / diagnostics
- backend-neutral runtime transport

がある。

まずこれらの組合せで stock `wp_insert_term()` を表現し、実consumer proofで不足が出た場合だけgeneric capabilityを追加する。

## WordPress model projection

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

Kintone proofではTerm / TermTaxonomyを別Appへbindingしてよい。App ID / field codeはbackend fixture/configurationだけが知る。

## Stock semantics to preserve

Parent=0 / aliasなし / ASCII nameのfirst proofに限定するが、最低限以下を壊さない。

1. generated slug is persisted with the Term entity
2. Term entity and TermTaxonomy context receive independent generated identities
3. `(term, taxonomy)` context can be resolved after create
4. same taxonomy/parent/nameのduplicate create is rejected with WordPress `term_exists` semantics
5. duplicate rejection does not create a second Term or TermTaxonomy record
6. WordPress's final duplicate-confidence check is not replaced with a fake unconditional success

## High-level query projection

Stock WordPress term creation invokes `get_terms()` / `get_term_by()` / `term_exists()` for name/slug/context checks. `WP_Term_Query` normally generates JOIN SQL.

Extend the WordPress-owned `terms_pre_query` projection only for exact bounded shapes needed by this proof:

- known term ID + taxonomy
- name + taxonomy + parent
- slug + taxonomy
- bounded candidate ID sets

The adapter may issue multiple bounded Hibari QueryIR requests and construct the WordPress result shape. It must not enable generic JOIN execution.

## Direct SQL boundary

Stock `wp_insert_term()` also issues direct SQL. Support only exact shapes needed by the proof:

- INSERT into `wp_terms`
- INSERT into `wp_term_taxonomy`
- exact post-insert term/taxonomy lookup JOIN, semantically projected to bounded Hibari queries
- exact final duplicate-confidence JOIN, semantically projected to bounded Hibari queries

If the duplicate cleanup DELETE path is reached by the proof, translate only those exact Term/TermTaxonomy deletes. Do not add generic JOIN SQL translation.

## Uniqueness semantics

WordPress uniqueness is not a single physical database unique key in this path; it combines Term data and taxonomy context and performs check-before-insert plus a final confidence check.

Hibari must not claim atomic uniqueness if the backend cannot guarantee it.

For the first kintone proof:

- bounded candidate checks may be emulated through multiple QueryIR requests
- ordinary Term/TermTaxonomy inserts may remain native
- race-free cross-App composite uniqueness is **not claimed**
- any limitation must stay explicit in diagnostics/docs rather than being described as backend-native uniqueness

## First proof scope

Stock public APIs, unchanged:

```text
wp_insert_term("Hibari Category", "category")
term_exists(createdTermId, "category")
wp_insert_term("Hibari Category", "category")  # duplicate
```

Acceptance behavior:

- first insert returns `term_id` and `term_taxonomy_id`
- created Term has persisted name=`Hibari Category`, slug=`hibari-category`
- created TermTaxonomy has taxonomy=`category`, parent=0, count=0
- `term_exists()` resolves the created pair from backend state
- second insert returns `WP_Error('term_exists', ...)`
- backend still contains exactly one logical Term and one TermTaxonomy context for the created category

## Acceptance criteria

- [ ] no new WordPress/SQL/kintone-specific concept is added to Hibari core
- [ ] existing QueryIR / MutationIR / planner/runtime remain the execution model
- [ ] Term and TermTaxonomy are ordinary backend-neutral models
- [ ] exact WordPress term-query shapes use bounded semantic projection before JOIN SQL execution
- [ ] only exact direct SQL shapes required by stock `wp_insert_term()` are admitted
- [ ] arbitrary JOIN remains rejected by existing `HIB-WP-JOIN-*` behavior
- [ ] first stock `wp_insert_term()` creates Term + TermTaxonomy through KintoneBackend
- [ ] later `term_exists()` observes created backend state
- [ ] duplicate stock `wp_insert_term()` returns `term_exists` error
- [ ] duplicate attempt creates no second logical Term/TermTaxonomy pair
- [ ] WordPress/core contain no kintone App ID / field code
- [ ] previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not add generic SQL JOIN AST/executor
- do not add a WordPress Term type to core
- do not denormalize all taxonomy state into one JSON blob just to bypass semantics
- do not claim atomic cross-App uniqueness
- do not broaden arbitrary `WP_Term_Query`
- do not add alias/group semantics in this child
- do not add nested hierarchy traversal in this child
- do not add termmeta or term-count recomputation in this child
- do not weaken existing unsupported SQL diagnostics

## Completion evidence required

- stock WordPress 7.1 public API output
- fake Kintone request evidence for Term create/read and TermTaxonomy create/read
- duplicate attempt evidence showing no second logical pair
- exact semantic JOIN projections documented
- full CI with all previous proofs green
- exact revision/run recorded before moving to `issues/closed`

## Non-goals

- arbitrary `WP_Term_Query`
- aliases / `term_group`
- non-root hierarchical parent creation
- termmeta
- term count maintenance
- taxonomy relationship membership (already proven separately)
- custom taxonomy/plugin compatibility
- live kintone credentials
