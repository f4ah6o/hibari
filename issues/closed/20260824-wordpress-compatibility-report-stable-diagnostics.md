# Hibari WordPress compatibility report with stable diagnostics

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Phase

Phase 4: compatibility tooling

## Goal

WordPress consumer の既存 `SqlPreflight` を backend 実行前の compatibility tooling として再利用し、既知 SQL セットを machine-readable / deterministic に判定できる report contract を実証した。

この child では source/plugin parser や `hibari check` CLI へ広げず、scanner/CLI が後から消費できる stable diagnostic report layer に限定した。

## Proven contract

Input:

```text
named SQL cases
  id
  sql
```

Output:

```text
version
profile
compatible
summary
items[]
  id
  classification
  operation
  diagnostics[]
    code
    severity
    capability
    message
```

`CompatibilityReport::inspect()` は explicit SQL cases だけを受け取り、既存 `SqlPreflight` を呼ぶ。portable case は `SqlPlan` を report item に投影し、unsupported case は既存 `CompatibilityException` の code/capability/message をそのまま保存する。

report generation は WordPress bootstrap、Bridge、backend、kintone transport を必要としない。

## Stable diagnostic families proven

- `HIB-WP-JOIN-001` / `query.join`
- `HIB-WP-AGG-001` / `query.aggregate`
- `HIB-WP-TXN-001` / `transaction.interactive`
- `HIB-WP-DDL-001` / `schema.migration`
- `HIB-WP-SUBQUERY-001` / `query.subquery`

Golden report summary:

```json
{
  "version": 1,
  "profile": "wordpress-portable-v0",
  "compatible": false,
  "summary": {
    "total": 6,
    "portable": 1,
    "unsupported": 5
  }
}
```

The full `items` payload is golden-tested in `packages/wordpress/test/fixtures/compatibility-report.golden.json`, including stable operation, diagnostic code, severity, capability, and message values.

## Acceptance criteria

- [x] reusable WordPress compatibility report contract がある
- [x] existing `HIB-WP-*` diagnostics are preserved exactly
- [x] portable/unsupported summary counts are deterministic
- [x] whole-report `compatible` flag is derived from unsupported count
- [x] representative unsupported families are covered by golden assertions
- [x] report generation requires no WordPress bootstrap and no backend requests
- [x] existing WordPress/core/kintone/Prisma proofs remain green

## Guardrails preserved

- no PHP source parser in this child
- no plugin filesystem scanner in this child
- no new generic SQL execution
- no kintone-specific diagnostic in WordPress consumer
- no duplicate diagnostic-code registry in core
- no CLI surface yet

## Completion evidence

- issue definition revision: `3b1ead22aa1af2f51b47e1504b2f194def7c5e7b`
- implementation revision: `2d670cb3dfc9b1e7424e9e28afe13055362a9a44`
- PR: #21
- PR merge-test revision: `6c5dea5372988152c158b1f7f1b8b9e8b8395339`
- CI run #252 / `32688950783`: success, 16/16 jobs green
- `wordpress-proof` job `97319020062`: success
- exact focused output ends with:
  - `WordPress compatibility report stable diagnostics proof: ok`
  - `WordPress 7.1 db.php boundary proof: ok`
- deterministic report observed by CI:
  - `version = 1`
  - `profile = wordpress-portable-v0`
  - `compatible = false`
  - `total = 6`
  - `portable = 1`
  - `unsupported = 5`
- the golden proof validates the exact ordered diagnostic list:
  - `HIB-WP-JOIN-001`
  - `HIB-WP-AGG-001`
  - `HIB-WP-TXN-001`
  - `HIB-WP-DDL-001`
  - `HIB-WP-SUBQUERY-001`
