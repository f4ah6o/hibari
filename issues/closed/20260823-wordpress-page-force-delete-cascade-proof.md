# Hibari WordPress page force-delete cascade proof

## Status

Closed

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

以前のcontent CRU childでは、WordPress deleteが comments / postmeta / taxonomy relationships / revisions などのdependent stateを掃除するため、関連domainが未実装の時点ではdeleteをclaimしなかった。現在は PostMeta / Comment / Relation Edge が明示的なcontractsとして存在するため、cascade境界を実際にdogfoodした。

## Proven public flow

Unchanged stock APIs:

```text
wp_insert_post(page, draft)
add_post_meta(page, "hibari_delete_meta", "before-delete")
wp_insert_comment(comment_post_ID = page)
wp_delete_post(page_id, true)
get_post(page_id)
get_post_meta(page_id, ...)
get_comment(comment_id)
```

Observed backend lifecycle:

```text
stock wp_delete_post()
  -> bounded Post child/revision/attachment selectors
  -> bounded Comment selector
  -> stock wp_delete_comment()
       -> bounded child Comment selector
       -> bounded CommentMeta selector
       -> Comment delete
  -> bounded PostMeta selector
  -> delete_metadata_by_mid()
       -> metadata-by-id read
       -> PostMeta delete
  -> final Post delete
```

WordPress Core owns the cascade ordering. Hibari adds no WordPress-specific cascade primitive.

## Narrow consumer additions

Three WordPress-consumer lifecycle translators were added. They lower exact stock 7.1 shapes to existing backend-neutral IR:

- `PostLifecycleSqlTranslator`
  - parent/type-scoped Post reads
  - bounded parent updateMany
  - final Post delete by ID
- `CommentLifecycleSqlTranslator`
  - comment IDs by post/parent
  - bounded parent updateMany
  - Comment delete by ID
- `MetadataLifecycleSqlTranslator`
  - owner-scoped metadata ID enumeration
  - metadata row lookup by ID
  - metadata delete by ID
  - shared across PostMeta / CommentMeta / UserMeta configuration

No generic SQL DELETE engine, JOIN engine, aggregate engine, or WordPress-specific core contract was introduced.

## SHORTINIT isolation

The proof intentionally remains a focused SHORTINIT integration proof. Stock Core's revision helper file is loaded because Metadata APIs call `wp_is_post_revision()`, while revision persistence remains disabled for the brand-new draft page.

The proof removes only unrelated default hooks whose dependent state is absent and whose implementations/query families are outside this child:

- font-face/family delete hooks
- navigation-menu post delete cleanup
- customize-changeset dependent auto-draft cleanup

No font posts, menu items, customize changesets, or revisions are created. Their delete semantics are not claimed.

## Acceptance criteria

- [x] stock `wp_delete_post($id, true)` is used unchanged
- [x] existing `Post` contract performs the final page-row delete
- [x] existing Dynamic Attributes contract removes dependent PostMeta
- [x] existing `Comment` contract removes dependent comments
- [x] later `get_post()` returns absence
- [x] later `get_post_meta()` observes no page metadata
- [x] later `get_comment()` observes no dependent comment
- [x] no WordPress-specific cascade contract is added to `@hibari/core`
- [x] unsupported unrelated SQL remains fail-closed
- [x] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails preserved

- no generic SQL DELETE engine
- no WordPress cascade primitive in core
- no fixture-side fake dependent cleanup
- no silent orphaning of the proven Comment/PostMeta state
- no revision semantics claimed
- no attachment/file deletion claimed
- no normal-post taxonomy relationship cascade claimed by this child
- no live kintone credentials required

## Completion evidence

- proof branch revision: `cee66626abedd857ee9e88327cc09cfa3857f16d`
- PR merge-test revision used by CI: `8512350a3b81a1ec034ee971ce382bab233b7c74`
- CI #241 / run `32617891122`: success, 15/15 jobs green
- `wordpress-page-delete-proof` job `97141451217`: success
- exact proof output:
  - `WordPress page force delete cascade -> Hibari -> KintoneBackend proof: ok`
- fake Kintone request evidence confirms:
  - app 85 creates the draft page
  - app 86 creates `hibari_delete_meta = before-delete`
  - app 92 creates the dependent comment
  - app 85 receives bounded `Post_parent = 1` selectors for page/revision/attachment lifecycle checks
  - app 92 receives bounded `Comment_post_ID = 1` and `Comment_parent = 1` selectors
  - app 93 receives the bounded dependent CommentMeta selector
  - app 92 receives an actual DELETE for comment ID 1
  - app 86 receives the owner-scoped `Post_id = 1` selector, metadata-by-id read, and actual DELETE for metadata ID 1
  - app 85 receives the final actual DELETE for Post ID 1
  - subsequent stock public reads produce backend lookups and observe no Post, PostMeta, or Comment record
- the fake Kintone runtime does not perform a cascade itself; records disappear only when the actual Hibari/Kintone DELETE requests arrive
- all existing 14 CI jobs remain green on the same revision
- CI `npm install` still reports the pre-existing 3 high severity vulnerabilities; they are unrelated to this child and remain unresolved
