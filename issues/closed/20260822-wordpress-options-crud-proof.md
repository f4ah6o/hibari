# Hibari WordPress options CRUD proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Goal

Stock WordPress 7.1 の public Options API を変更せず、`get_option()` / `add_option()` / `update_option()` / `delete_option()` を Hibari runtime 経由で `KintoneBackend` に永続化できることを実証する。

WordPress 固有 SQL は `packages/wordpress` で backend-neutral QueryIR / MutationIR に正規化し、MySQL compatibility を core/runtime/backend に持ち込まない。

## Mapping

```text
wp_options.option_name  -> Option.name (unique)
wp_options.option_value -> Option.value
wp_options.autoload     -> Option.autoload
```

`INSERT ... ON DUPLICATE KEY UPDATE` は `Option.name` を unique selector とする Hibari `upsert` へ正規化する。

## Acceptance criteria

- [x] stock WordPress 7.1 public option APIs are used unchanged
- [x] option SELECT SQL translates to QueryIR
- [x] Core `$wpdb->update()` SQL translates to `update` MutationIR
- [x] Core `$wpdb->delete()` SQL translates to `delete` MutationIR
- [x] Core option INSERT/upsert SQL translates to Hibari `upsert`
- [x] Option name is modeled as a backend-neutral unique key
- [x] `get/add/update/delete` sequence is green through `KintoneBackend`
- [x] fake Kintone state demonstrates persistence across calls
- [x] no generic MySQL compatibility is added to core/runtime-http/kintone
- [x] previous CI proofs remain green

## Completion evidence

- `WordPressSqlTranslator.php` owns the narrow stock `wp_options` SQL mappings.
- `HibariWpdb::process_fields()` keeps WordPress format pairing without MySQL column metadata.
- The mutable fake Kintone transport runs behind the real `KintoneBackend`.
- The stock WordPress proof reads `hibari_existing=before`, updates it to `after`, creates `hibari_added=created`, deletes it, then observes the caller-supplied default.
- The Kintone request log proves GET, PUT, POST, DELETE operations and the `after` / `created` payloads.
- GitHub Actions CI run #83 (`32530421185`) passed all four jobs: `test`, `wordpress-proof`, `wordpress-runtime-proof`, `wordpress-options-proof`.
- The proof output is `WordPress options CRUD -> Hibari -> KintoneBackend proof: ok`.

## Non-goals

- WordPress schema/DDL
- posts/pages/users/taxonomy/comments
- autoload bulk-loading optimization
- wp_postmeta/EAV
- arbitrary plugin SQL
- complete MySQL parser
- live kintone credentials
