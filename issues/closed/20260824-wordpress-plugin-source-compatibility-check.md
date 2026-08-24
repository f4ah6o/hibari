# Hibari WordPress plugin source compatibility check

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Phase

Phase 4: compatibility tooling

## Goal

前段で確立した deterministic `CompatibilityReport` を WordPress plugin/source の static check に接続した。

PHP source は実行せず `token_get_all()` で走査し、common `$wpdb` query methods に直接渡される静的 SQL literal を抽出して既存 `SqlPreflight` / `CompatibilityReport` で判定する。静的に解決できない SQL は compatible と誤判定せず、scan incomplete として stable diagnostic を返す。

## Proven scanner scope

Recognized calls:

```text
$wpdb->query(...)
$wpdb->get_results(...)
$wpdb->get_row(...)
$wpdb->get_col(...)
$wpdb->get_var(...)
```

Static SQL scope:

- direct single/double quoted string literal
- literal-only concatenation
- dynamic variables/interpolation/nested calls are not evaluated

`PluginCompatibilityScanner::inspectDirectory()` walks `.php` files in sorted path order, tokenizes source, and emits deterministic call identities using file/line/method/ordinal.

Static SQL cases are passed to the existing `CompatibilityReport`. `CompatibilityReport` now preserves optional source file/line evidence without changing the existing report shape for callers that do not provide source metadata.

Dynamic/uninspectable calls emit:

- code: `HIB-WP-SCAN-001`
- severity: `warning`
- capability: `wordpress.staticSql`

Any scan diagnostic makes `complete=false`, and an incomplete scan cannot produce `compatible=true`.

## Acceptance criteria

- [x] PHP source is tokenized, never executed
- [x] directory traversal and report ordering are deterministic
- [x] static literal SQL reaches existing `CompatibilityReport`
- [x] source file/line is preserved in report items
- [x] unsupported literal SQL preserves existing stable `HIB-WP-*` diagnostic codes
- [x] dynamic/uninspectable SQL produces stable `HIB-WP-SCAN-001`
- [x] an incomplete scan cannot report `compatible=true`
- [x] no backend/kintone requests are required
- [x] existing proofs remain green

## Guardrails preserved

- no general PHP AST/parser dependency
- no eval/include/require of scanned plugin source
- no attempt to infer runtime string values
- no generic SQL parser/executor
- no CLI yet
- dynamic SQL is not silently ignored

## Completion evidence

- issue definition revision: `590d44ed626d68c64a182f3d9dba1d69890da68e`
- implementation revision: `1e34262a5a286d801e0b2893cc26f8e839480754`
- PR: #22
- PR merge-test revision: `1692419e8cf0359fc9e141327f84cdeb24f6d8f3`
- CI run #255 / `32689352048`: success, 16/16 jobs green
- `wordpress-proof` job `97320101891`: success
- exact focused output ends with:
  - `WordPress compatibility report stable diagnostics proof: ok`
  - `WordPress plugin static compatibility check proof: ok`
  - `WordPress 7.1 db.php boundary proof: ok`
- deterministic fixture scan observed by CI:
  - `files = 1`
  - `sqlCases = 2`
  - `portable = 1`
  - `unsupported = 1`
  - `uninspectable = 1`
  - `complete = false`
  - `compatible = false`
- source evidence is stable:
  - portable literal: `hibari-fixture-plugin.php:6`
  - unsupported JOIN: `hibari-fixture-plugin.php:10`, preserving `HIB-WP-JOIN-001`
  - dynamic SQL: `hibari-fixture-plugin.php:15`, producing `HIB-WP-SCAN-001`
- fixture source contains a top-level `SCANNED_PLUGIN_EXECUTED` exception sentinel; the proof completes successfully, demonstrating the scanned plugin source was tokenized rather than executed
- report generation/scanning performs no WordPress bootstrap and no backend/kintone transport calls
