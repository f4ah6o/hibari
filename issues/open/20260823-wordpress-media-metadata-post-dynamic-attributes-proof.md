# Hibari WordPress media metadata via Post + Dynamic Attributes proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-comment-entity-dynamic-attributes-proof.md`

## Goal

Stock WordPress 7.1 の media metadata 基本操作を、新しい Media / Attachment core contract や blob/file storage abstractionを追加せず、既存の ordinary `Post` model + Dynamic Attributes で成立させる。

WordPress Coreのattachmentが `wp_posts.post_type = attachment` と `wp_postmeta` を利用する事実をconsumer側で受け止め、Hibari coreにはPost subtypeやWordPress media概念を持ち込まない。

## Design hypothesis

```text
WordPress Attachment
  -> existing Post model (post_type = attachment)

_wp_attached_file
_wp_attachment_metadata
  -> existing PostMeta / Dynamic Attributes
```

最初からbinary uploadや画像変換まで扱わない。datastore compatibility proofとして、attachment entityとmetadataの永続化・再読込・更新を実証する。

## First proof scope

Use unchanged stock public APIs where possible:

```text
wp_insert_attachment(...)
get_post(...)
update_attached_file(...)
get_attached_file(...)
wp_update_attachment_metadata(...)
wp_get_attachment_metadata(...)
```

Deterministic fixtureで1 attachmentを作成し、以下を検証する。

- generated integer attachment identity
- `get_post()` returns `post_type = attachment`
- MIME type / title / parent scalar fields round-trip as ordinary Post fields
- `_wp_attached_file` is persisted via PostMeta Dynamic Attributes
- structured `_wp_attachment_metadata` array is serialized by WordPress, stored opaquely through Hibari, and unserialized by WordPress on read
- metadata update is visible through later stock API read
- no attachment-specific core model or backend API leaks into WordPress consumer code

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

Fixture file paths may be logical deterministic paths used only to exercise WordPress metadata APIs. No live object storage is required.

## SQL / capability boundary

Re-use the existing proven `wp_posts` and `wp_postmeta` paths. Do not add generic SQL or a new core capability merely because the WordPress API name says “attachment”.

If stock WordPress 7.1 emits an exact additional SQL shape, add it only at the WordPress consumer boundary and only if it preserves the existing Post / Dynamic Attributes semantics.

## Acceptance criteria

- [ ] no Media / Attachment concept is added to Hibari core
- [ ] attachment entity is represented by existing ordinary Post model
- [ ] attachment metadata reuses PostMeta / Dynamic Attributes
- [ ] stock `wp_insert_attachment()` creates one attachment through KintoneBackend
- [ ] stock `get_post()` observes attachment backend state
- [ ] `update_attached_file()` / `get_attached_file()` round-trip through Dynamic Attributes
- [ ] structured `wp_update_attachment_metadata()` / `wp_get_attachment_metadata()` round-trip through Dynamic Attributes
- [ ] WordPress serialization remains WordPress-owned opaque data to Hibari
- [ ] backend App IDs / field codes remain only in proof infrastructure/backend binding
- [ ] arbitrary media-library query / binary storage semantics are not silently emulated
- [ ] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not add blob/file storage to core
- do not add Attachment as a new core entity type
- do not parse WordPress serialized attachment metadata inside Hibari
- do not implement image manipulation
- do not broaden arbitrary `WP_Query`
- do not require live kintone credentials

## Completion evidence required

- stock WordPress 7.1 public attachment/metadata API output
- fake Kintone request evidence for attachment Post create/read
- fake Kintone request evidence for `_wp_attached_file` and `_wp_attachment_metadata`
- full CI including every prior proof
- exact revision/run recorded before moving to `issues/closed`
