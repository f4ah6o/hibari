# Hibari WordPress post content create/read/update proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-runtime-transport-proof.md`
- `issues/closed/20260822-wordpress-options-crud-proof.md`

## Goal

Stock WordPress 7.1 の public post APIs を変更せず、page content の create / read / update を Hibari runtime 経由で `KintoneBackend` に永続化する。

最初のcontent proofは built-in `page` post type + `draft` status を使う。page は通常のcategory/tag taxonomyを要求せず、draftはslug uniqueness queryを避けられるため、`wp_posts` persistenceそのものを先に実証できる。

## Scope

Public API proof:

- `wp_insert_post()` creates a draft page
- `get_post()` reads it back
- `wp_update_post()` changes title/content
- later `get_post()` observes the persisted change

WordPress SQL translator owns explicit `wp_posts` statement shapes:

- INSERT into `wp_posts` -> `Post` insert MutationIR
- SELECT `*` from `wp_posts` by `ID` -> QueryIR
- UPDATE `wp_posts` by `ID` -> update MutationIR

Mapping uses backend-neutral names, for example:

```text
wp_posts.ID           -> Post.id
wp_posts.post_title   -> Post.title
wp_posts.post_content -> Post.content
wp_posts.post_status  -> Post.status
wp_posts.post_type    -> Post.type
wp_posts.post_parent  -> Post.parentId
```

The complete WordPress post-row fields needed by stock `WP_Post` are mapped by the consumer adapter; kintone field codes remain backend configuration.

## Delete boundary

`wp_delete_post()` is intentionally out of scope in this child. WordPress deletion also cleans comments, postmeta, terms, revisions and related state. Hibari must not report a semantically correct post delete while those domains are absent.

Delete will be enabled only after the required dependent domains have explicit projections/semantics.

## Acceptance criteria

- [ ] stock WordPress 7.1 `wp_insert_post()` is used unchanged
- [ ] stock `get_post()` reads the inserted page through Hibari
- [ ] stock `wp_update_post()` persists title/content changes
- [ ] later `get_post()` observes the changes from KintoneBackend state
- [ ] `wp_posts` SQL translation is owned only by the WordPress consumer
- [ ] Kintone system ID is exposed to WordPress only as ordinary `Post.id` / SQL `ID`
- [ ] fake Kintone REST proves create/read/update payloads
- [ ] no taxonomy/comment/postmeta semantics are silently emulated
- [ ] previous CI proofs remain green

## Non-goals

- `wp_delete_post()` / trash lifecycle
- revisions
- categories/tags/custom taxonomies
- comments
- postmeta
- attachments
- WP_Query list/search compatibility
- arbitrary plugin SQL
- generic MySQL compatibility
- live kintone credentials
