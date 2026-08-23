# Hibari WordPress page force-delete cascade proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-comment-entity-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-taxonomy-relation-projection-proof.md`
- `issues/closed/20260823-wordpress-draft-publish-state-proof.md`
- `issues/closed/20260823-wordpress-full-bootstrap-standard-theme-proof.md`

## Goal

親issue Phase 3 の `basic content CRUD` で意図的に残していた Delete を再開する。

Stock WordPress 7.1 の public `wp_delete_post($post_id, true)` を変更せず、まず built-in `page` の force-delete を Hibari + KintoneBackend で意味を保って実行できることを証明する。

以前のcontent CRU childでは、WordPress deleteが comments / postmeta / taxonomy relationships / revisions などのdependent stateを掃除するため、関連domainが未実装の時点ではdeleteをclaimしなかった。現在は PostMeta / Comment / Relation Edge が明示的なcontractsとして存在するため、cascade境界を実際にdogfoodする。

## First proof scope

Use unchanged stock public APIs:

```text
wp_insert_post(page, draft)
add_post_meta(page, "hibari_delete_meta", "before-delete")
wp_insert_comment(comment_post_ID = page)
wp_delete_post(page_id, true)
get_post(page_id)
get_post_meta(page_id, ...)
get_comment(comment_id)
```

Verify:

- page Post row is deleted from the existing `Post` binding
- dependent PostMeta rows for the page are deleted through the existing Dynamic Attributes path
- dependent Comment rows are deleted through the existing Comment contract
- later public reads observe absence
- no fake successful SQL result or hidden client-side cascade is introduced
- the WordPress consumer may decompose the stock delete lifecycle into already-supported bounded semantic operations, but core must not gain a WordPress-specific cascade primitive

A built-in page is chosen first because it has no normal category/tag membership. Taxonomy-edge cascade for stock `post` remains separate evidence unless it naturally occurs in this proof.

## Revision boundary

WordPress revision cleanup is a separate domain. This proof uses a newly-created draft page with no revisions and may explicitly isolate revision-only callbacks/queries in the proof harness if stock Core asks for revision traversal despite there being no revision state.

The proof must not invent fake revision rows or claim revision deletion semantics.

## Compatibility boundary

Add only exact stock WordPress 7.1 SQL/semantic shapes needed by this public delete flow:

- `DELETE FROM wp_posts WHERE ID = ...` -> ordinary `Post` delete MutationIR
- dependent metadata/comment cleanup must reuse existing consumer translators/contracts where their semantics already match
- if WordPress emits a new bounded selector for dependent rows, project only that exact existing-domain shape

Do not enable generic DELETE/JOIN/aggregate/subquery SQL.

## Acceptance criteria

- [ ] stock `wp_delete_post($id, true)` is used unchanged
- [ ] existing `Post` contract performs the final page-row delete
- [ ] existing Dynamic Attributes contract removes dependent PostMeta
- [ ] existing Comment contract removes dependent comments
- [ ] later `get_post()` returns absence
- [ ] later `get_post_meta()` observes no page metadata
- [ ] later `get_comment()` observes no dependent comment
- [ ] no WordPress-specific cascade contract is added to `@hibari/core`
- [ ] unsupported unrelated SQL remains fail-closed
- [ ] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not add a generic SQL DELETE engine
- do not add a WordPress cascade primitive to core
- do not fake dependent cleanup in the Kintone fixture
- do not silently leave known dependent state orphaned while reporting success
- do not add revision semantics in this child
- do not broaden to attachment/file deletion
- do not require live kintone credentials

## Completion evidence required

- exact stock WordPress 7.1 public API output
- fake Kintone evidence showing dependent Comment/PostMeta deletes and final Post delete
- proof that later public reads observe absence
- full CI including every previous proof
- exact final revision/run recorded before moving this issue to `issues/closed`
