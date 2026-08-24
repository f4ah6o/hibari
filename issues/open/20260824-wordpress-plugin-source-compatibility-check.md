# Hibari WordPress plugin source compatibility check

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Phase

Phase 4: compatibility tooling

## Goal

前段で確立した deterministic `CompatibilityReport` を、WordPress plugin/source の static check に接続する。

PHP source を実行せず `token_get_all()` で走査し、`$wpdb` の common query methods に直接渡される静的 SQL literal を抽出して既存 `SqlPreflight` / `CompatibilityReport` で判定する。静的に解決できない SQL は compatible と誤判定せず、scan incomplete として明示する。

## First proof scope

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

Output must preserve source evidence:

```text
complete
compatible
scan summary
compatibility report items
  source.file
  source.line
scan diagnostics
  HIB-WP-SCAN-001 for uninspectable SQL call
```

## Acceptance criteria

- [ ] PHP source is tokenized, never executed
- [ ] directory traversal and report ordering are deterministic
- [ ] static literal SQL reaches existing `CompatibilityReport`
- [ ] source file/line is preserved in report items
- [ ] unsupported literal SQL preserves existing stable `HIB-WP-*` diagnostic codes
- [ ] dynamic/uninspectable SQL produces stable `HIB-WP-SCAN-001`
- [ ] an incomplete scan cannot report `compatible=true`
- [ ] no backend/kintone requests are required
- [ ] existing proofs remain green

## Guardrails

- no general PHP AST/parser dependency
- no eval/include/require of scanned plugin source
- no attempt to infer runtime string values
- no generic SQL parser/executor
- no CLI yet; this child proves the source-check contract first
- do not silently ignore dynamic SQL

## Completion evidence required

- deterministic fixture plugin source and golden report
- proof that scanned PHP code is never executed
- stable source locations and diagnostic codes
- full CI run and revision
