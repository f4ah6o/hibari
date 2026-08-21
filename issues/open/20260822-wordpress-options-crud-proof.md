# Hibari WordPress options CRUD proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-db-dropin-boundary-proof.md`
- `issues/closed/20260822-wordpress-runtime-transport-proof.md`

## Goal

Stock WordPress 7.1 の public Options API を変更せず、`get_option()` / `add_option()` / `update_option()` / `delete_option()` を Hibari runtime 経由で `KintoneBackend` に永続化できることを実証する。

WordPress が使う MySQL-specific statement shape は WordPress consumer で意味を読み取り、backend-neutral QueryIR / MutationIR に正規化する。MySQL compatibility を core/runtime/backend に持ち込まない。

## WordPress 7.1 source semantics

Current Core behavior:

- `get_option()` reads a named option
- `add_option()` uses an INSERT with `ON DUPLICATE KEY UPDATE`
- `update_option()` reads the current value, may read `autoload`, then uses `$wpdb->update()`
- `delete_option()` reads `autoload`, then uses `$wpdb->delete()`

Hibari mapping:

```text
wp_options.option_name  -> Option.name (unique)
wp_options.option_value -> Option.value
wp_options.autoload     -> Option.autoload
```

`add_option()`'s MySQL upsert syntax is normalized to Hibari `upsert` semantics using the unique `Option.name` selector.

## Scope

### WordPress SQL translator

Support the stock Core option statements required by the proof:

- SELECT one option column by `option_name`, optionally `LIMIT 1`
- UPDATE option fields by `option_name`
- DELETE option by `option_name`
- INSERT option name/value/autoload with Core's `ON DUPLICATE KEY UPDATE` shape -> Hibari upsert

Do not add a generic MySQL parser.

### wpdb result behavior

The bridge/runtime path must return the affected counts expected by WordPress Core so the public APIs preserve their boolean behavior.

### Kintone-backed proof

Use an in-memory fake Kintone REST transport with mutable records behind the real `KintoneBackend`:

1. seed an option
2. `get_option()` reads it
3. `update_option()` changes it
4. subsequent `get_option()` observes the new value
5. `add_option()` creates a new option
6. `delete_option()` removes it
7. subsequent `get_option()` returns the supplied default

No WordPress application code references kintone details.

## Acceptance criteria

- [ ] stock WordPress 7.1 public option APIs are used unchanged
- [ ] option SELECT SQL translates to QueryIR
- [ ] Core `$wpdb->update()` option SQL translates to `update` MutationIR
- [ ] Core `$wpdb->delete()` option SQL translates to `delete` MutationIR
- [ ] Core `INSERT ... ON DUPLICATE KEY UPDATE` translates to Hibari `upsert`
- [ ] Option name is modeled as a backend-neutral unique key
- [ ] `get/add/update/delete` option sequence is green through `KintoneBackend`
- [ ] fake Kintone state demonstrates persistence across calls
- [ ] no generic MySQL compatibility is added to core/runtime-http/kintone
- [ ] previous CI proofs remain green

## Non-goals

- WordPress installation schema/DDL
- posts/pages/users/taxonomy/comments
- autoload bulk-loading optimization
- wp_postmeta/EAV
- arbitrary plugin option SQL
- complete MySQL parser
- live kintone credentials
