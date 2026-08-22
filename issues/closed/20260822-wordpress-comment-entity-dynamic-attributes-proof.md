# Hibari WordPress comment entity / Dynamic Attributes proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-user-entity-dynamic-attributes-proof.md`

## Goal

Stock WordPress 7.1 の Comment entity と CommentMeta を、Hibari core に WordPress-specific comment abstraction や aggregate engine を追加せず、既存 SchemaIR / QueryIR / MutationIR と Dynamic Attributes で成立させる。

Posts/PostMeta、Users/UserMeta に続く3つ目の `ordinary entity + Dynamic Attributes` consumer proof とする。

## Source-derived constraints

Stock `wp_insert_comment()` は:

- `wp_comments` に row をinsertする
- generated `comment_ID` を得る
- approved commentでは post comment-count maintenanceを起動する
- `get_comment()` で保存結果を再取得する
- `comment_meta` が渡された場合は `add_comment_meta()` で保存する

Stock `wp_update_comment()` は:

- `get_comment(..., ARRAY_A)` で既存rowを読む
- merged comment rowをupdateする
- optional `comment_meta` を `update_comment_meta()` で更新する
- comment-count maintenanceを起動する

Comment count maintenanceは `SELECT COUNT(*)` を含むaggregate side domainであり、このchildでは有効化しない。

## Model projection

```text
wp_comments
  comment_ID           -> Comment.id
  comment_post_ID      -> Comment.postId
  comment_author       -> Comment.author
  comment_author_email -> Comment.authorEmail
  comment_author_url   -> Comment.authorUrl
  comment_author_IP    -> Comment.authorIp
  comment_date         -> Comment.date
  comment_date_gmt     -> Comment.dateGmt
  comment_content      -> Comment.content
  comment_karma        -> Comment.karma
  comment_approved     -> Comment.approved
  comment_agent        -> Comment.agent
  comment_type         -> Comment.type
  comment_parent       -> Comment.parentId
  user_id              -> Comment.userId

wp_commentmeta
  meta_id    -> DynamicAttribute.id
  comment_id -> DynamicAttribute.ownerId
  meta_key   -> DynamicAttribute.key
  meta_value -> DynamicAttribute.value
```

Backend App IDs / field codes remain only in backend fixture/configuration.

## Proven public API path

```text
wp_defer_comment_counting(true)
wp_insert_comment(..., comment_meta => ...)
get_comment(...)
get_comment_meta(...)
wp_update_comment(..., comment_meta => ...)
get_comment(...)
get_comment_meta(...)
```

The proof creates one unapproved comment (`comment_approved = 0`) against deterministic post ID 42. The Post entity itself is not mutated by this proof.

## Comment-count boundary

`wp_defer_comment_counting(true)` is set for the focused proof and is intentionally not flushed.

This is scope isolation, not aggregate emulation:

- Comment entity semantics are proven
- CommentMeta semantics are proven
- metadata `unique=true` existence checks are recognized only as the narrow Dynamic Attributes existence shape and lowered to bounded ordinary queries
- `COUNT(*)` comment-count maintenance remains unsupported/unproven
- no fake comment-count aggregate result is returned

## Implementation notes

- `CommentSqlTranslator` accepts only the exact stock WordPress 7.1 Comment ID lookup / insert / update shapes required by this proof.
- `CommentmetaSqlTranslator` is only configuration over the existing `MetadataSqlTranslator`.
- `MetadataSqlTranslator` normalizes numeric metadata owner IDs whether WordPress emits them as bare numeric literals or quoted numeric literals. This is required because `comment_id` is not one of wpdb's integer field-type aliases.
- the SHORTINIT proof loads the same minimal WordPress HTML API dependency order needed by WordPress 7.1 KSES before `wp_update_comment()`.
- no Comment-specific contract was added to `@hibari/core`.

## Acceptance criteria

- [x] no WordPress Comment concept is added to Hibari core
- [x] Comment is an ordinary backend-neutral model using existing QueryIR / MutationIR
- [x] CommentMeta reuses the configurable Dynamic Attributes translator
- [x] stock `wp_insert_comment()` creates one Comment through KintoneBackend
- [x] stock `get_comment()` observes created backend state
- [x] initial `comment_meta` is persisted and readable through Dynamic Attributes
- [x] stock `wp_update_comment()` persists a basic content update
- [x] updated metadata is visible through stock Metadata API
- [x] comment identity remains stable across update
- [x] comment-count aggregate maintenance is explicitly deferred, not emulated
- [x] arbitrary `WP_Comment_Query`, JOIN, aggregate, transaction, and DDL remain unsupported unless already proven elsewhere
- [x] WordPress/core contain no kintone App ID / field code
- [x] previous core/kintone/Prisma/WordPress proofs remain green

## Completion evidence

Implementation proof revision:

`088f15893db2af7f7e78e1233ecc125b3c772413`

GitHub Actions:

- CI #192
- run id: `32599772005`
- result: **10/10 jobs green**
- `wordpress-comment-proof` job id: `97096195967`

Stock WordPress 7.1 output:

```text
WordPress Comment + CommentMeta Dynamic Attributes -> Hibari -> KintoneBackend proof: ok
```

Fake Kintone evidence proves:

- app 92: one Comment create with `Initial Hibari comment`
- app 92: bounded ID reads and one update preserving identity while changing content to `Updated Hibari comment`
- app 93: bounded owner/key existence lookup for `proof_key`
- app 93: CommentMeta create with `initial-meta`
- app 93: owner-scoped metadata reads
- app 93: CommentMeta update to `updated-meta`
- no Comment/Post count-maintenance aggregate request is sent to the backend proof path

All nine previously existing CI jobs remained green in the same run.

## Guardrails retained

- no aggregate execution added for comment counts
- no Comment-specific core contract
- no duplicated metadata engine
- no arbitrary `WP_Comment_Query`
- no moderation/status-transition workflow proof beyond persisted approval scalar
- no notifications/email behavior
- no comment deletion/cascade
- no nested comment traversal
- no live kintone credentials

## Non-goals

- comment count recomputation
- arbitrary `WP_Comment_Query`
- moderation/status-transition workflows
- notifications/email
- threaded descendants traversal
- comment deletion/cascade
- live kintone credentials
