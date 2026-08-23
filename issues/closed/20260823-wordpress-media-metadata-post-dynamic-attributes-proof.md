# Hibari WordPress media metadata via Post + Dynamic Attributes proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-comment-entity-dynamic-attributes-proof.md`

## Goal

Stock WordPress 7.1 の media metadata 基本操作を、新しい Media / Attachment core contract や blob/file storage abstractionを追加せず、既存の ordinary `Post` model + Dynamic Attributes で成立させる。

WordPress Coreのattachmentが `wp_posts.post_type = attachment` と `wp_postmeta` を利用する事実をconsumer側で受け止め、Hibari coreにはPost subtypeやWordPress media概念を持ち込まない。

## Design result

```text
WordPress Attachment
  -> existing Post model (post_type = attachment)

_wp_attached_file
_wp_attachment_metadata
  -> existing PostMeta / Dynamic Attributes
```

binary uploadや画像変換は扱わず、datastore compatibility proofとしてattachment entityとmetadataの永続化・再読込・更新を実証した。

## Proven public API path

Stock WordPress 7.1 の以下を変更せず利用した。

```text
wp_insert_attachment(...)
get_post(...)
update_attached_file(...)
get_attached_file(...)
wp_update_attachment_metadata(...)
wp_get_attachment_metadata(...)
```

Deterministic fixtureで1 attachmentを作成し、以下を確認した。

- generated integer attachment identity
- `get_post()` returns `post_type = attachment`
- MIME type / title / parent scalar fields round-trip as ordinary Post fields
- `_wp_attached_file` is persisted via PostMeta Dynamic Attributes
- structured `_wp_attachment_metadata` array is serialized by WordPress, stored opaquely through Hibari, and unserialized by WordPress on read
- nested metadata update is visible through later stock API read
- no attachment-specific core model or backend API leaks into WordPress consumer code

## Generic fix discovered by the proof

`HibariWpdb::_real_escape()` uses `addslashes()` before wpdb emits SQL. The existing configurable `MetadataSqlTranslator` only reversed escaped single quotes and backslashes, so a serialized PHP value containing escaped double quotes was persisted with `\"` still present and `maybe_unserialize()` could not reconstruct the array.

The consumer-side SQL literal decoder now reverses the exact `addslashes()` escape set (`NUL`, single quote, double quote, backslash). This is a generic Dynamic Attributes boundary fix, not a Media-specific parser. Hibari continues to treat serialized metadata as opaque data.

## SHORTINIT proof isolation

The proof uses `SHORTINIT` to avoid full site/plugin/theme boot. WordPress 7.1 attachment insertion and upload-path helpers rely on pieces normally loaded by the full bootstrap, so the harness explicitly supplies only the relevant stock environment/helpers:

- `WP_CONTENT_URL`
- `wp-includes/revision.php`
- stock slug short-circuit hooks for the unrelated media-library / slug-collision query domain
- removal of an otherwise registered template-slug callback whose implementation is intentionally omitted by SHORTINIT

No generic `WP_Query` support was added to Hibari for this child.

## Boundary

This child is not a file-storage implementation.

Out of scope:

- file upload transport
- binary/blob storage
- image resizing / thumbnail generation
- EXIF extraction
- filesystem existence checks as a Hibari responsibility
- attachment URL/CDN/storage-provider abstraction
- arbitrary media library `WP_Query`
- deletion/cascade

Fixture file paths are deterministic logical paths used only to exercise WordPress metadata APIs. No live object storage is required.

## Acceptance criteria

- [x] no Media / Attachment concept is added to Hibari core
- [x] attachment entity is represented by existing ordinary Post model
- [x] attachment metadata reuses PostMeta / Dynamic Attributes
- [x] stock `wp_insert_attachment()` creates one attachment through KintoneBackend
- [x] stock `get_post()` observes attachment backend state
- [x] `update_attached_file()` / `get_attached_file()` round-trip through Dynamic Attributes
- [x] structured `wp_update_attachment_metadata()` / `wp_get_attachment_metadata()` round-trip through Dynamic Attributes
- [x] WordPress serialization remains WordPress-owned opaque data to Hibari
- [x] backend App IDs / field codes remain only in proof infrastructure/backend binding
- [x] arbitrary media-library query / binary storage semantics are not silently emulated
- [x] all previous core/kintone/Prisma/WordPress proofs remain green

## Completion evidence

Implementation/proof revision:

- `13497c78e2ab4e4e5b225d964fc0a98a696ed980`

GitHub Actions:

- CI #204
- run id `32606533891`
- result: success
- all 11/11 jobs green
- media job id `97112250950`
- media output: `WordPress media metadata -> Post + Dynamic Attributes -> Hibari -> KintoneBackend proof: ok`

Fake Kintone evidence at the same revision confirms:

- app 85 `POST /k/v1/record.json` with `Post_type = attachment`, MIME, title, parent and ordinary Post fields
- app 85 `$id = 1` reads through the existing Post path
- app 86 `_wp_attached_file` insert with logical path `2026/08/hibari-proof.jpg`
- app 86 `_wp_attachment_metadata` insert containing WordPress-owned serialized nested metadata
- later app 86 update changes width `640 -> 800`, height `480 -> 600`, and nested thumbnail width `150 -> 160`
- later Metadata API read reconstructs the updated PHP array successfully

## Guardrails preserved

- no blob/file storage added to core
- no Attachment core entity type added
- no WordPress serialized metadata parser added to Hibari
- no image manipulation added
- arbitrary `WP_Query` remains unsupported
- no live kintone credentials required
