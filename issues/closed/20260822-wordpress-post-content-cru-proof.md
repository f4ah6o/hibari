# Hibari WordPress post content create/read/update proof

## Status

Closed

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

- [x] stock WordPress 7.1 `wp_insert_post()` is used unchanged
- [x] stock `get_post()` reads the inserted page through Hibari
- [x] stock `wp_update_post()` persists title/content changes
- [x] later `get_post()` observes the changes from KintoneBackend state
- [x] `wp_posts` SQL translation is owned only by the WordPress consumer
- [x] Kintone system ID is exposed to WordPress only as ordinary `Post.id` / SQL `ID`
- [x] fake Kintone REST proves create/read/update payloads
- [x] no taxonomy/comment/postmeta semantics are silently emulated
- [x] previous CI proofs remain green

## Completion evidence

- `WordPressSqlTranslator.php` owns the narrow stock `wp_posts` INSERT / SELECT-by-ID / UPDATE-by-ID mappings and emits backend-neutral `Post` QueryIR / MutationIR.
- Stock `wp_insert_post()` created a draft page through Hibari and fake Kintone app `85`; the request log showed `POST /k/v1/record.json` with title/content/status/type fields.
- Stock `get_post()` read the generated identity back through `Post.id`; the fake Kintone request log showed `$id = 1` queries.
- Stock `wp_update_post()` emitted a real Hibari update plan and reached `KintoneBackend`; the request log showed the identity/revision read followed by `PUT /k/v1/records.json` with `Hibari updated page` / `Hibari updated body`.
- A later uncached `get_post()` observed the persisted updated title/content from backend state.
- `WP_Post::to_array()` always asks for the virtual `page_template` property. Because this child explicitly excludes `wp_postmeta`, the proof primes the authoritative empty `post_meta` object-cache entry for the newly created no-meta page instead of adding fake postmeta SQL support.
- Default callbacks for revisions and old-slug/date metadata are explicitly removed in the proof bootstrap because revisions/postmeta are declared non-goals; the public `wp_insert_post()` / `wp_update_post()` implementations themselves are unchanged.
- GitHub Actions CI run #117 (`32550991844`) passed all five jobs: `test`, `wordpress-proof`, `wordpress-runtime-proof`, `wordpress-options-proof`, and `wordpress-post-content-proof`.
- Proof output: `WordPress post content CRU -> Hibari -> KintoneBackend proof: ok`.
- Production main evidence revision after domain isolation: `39373137f0b77ea2429f89bb94ad48f907c22d7a`.

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
