# Hibari WordPress compatibility report with stable diagnostics

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Phase

Phase 4: compatibility tooling

## Goal

WordPress consumer の既存 `SqlPreflight` を backend 実行前の compatibility tooling として再利用し、既知 SQL セットを machine-readable / deterministic に判定できる report contract を追加する。

この child では source/plugin parser や `hibari check` CLI まで広げない。まず scanner/CLI が後から消費できる、stable diagnostic codes を保つ最小 report layer を確立する。

## First proof scope

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

Requirements:

- portable SQL は `classification=portable`
- unsupported SQL は既存 `CompatibilityException` の stable code/capability/message を失わない
- JOIN / aggregate / transaction / DDL / subquery の代表 shape を固定する
- report generation 自体は bridge/backend/kintone を呼ばない
- input order に対して deterministic な JSON を返す
- source/plugin scanning はこの report contract の consumer として後続 child に分離する

## Acceptance criteria

- [ ] reusable WordPress compatibility report contract がある
- [ ] existing `HIB-WP-*` diagnostics are preserved exactly
- [ ] portable/unsupported summary counts are deterministic
- [ ] whole-report `compatible` flag is derived from unsupported count
- [ ] representative unsupported families are covered by golden assertions
- [ ] report generation requires no WordPress bootstrap and no backend requests
- [ ] existing WordPress/core/kintone/Prisma proofs remain green

## Guardrails

- no PHP source parser in this child
- no plugin filesystem scanner in this child
- no new generic SQL execution
- no kintone-specific diagnostic in WordPress consumer
- no duplicate diagnostic-code registry in core
- no CLI surface until the report contract is proven

## Completion evidence required

- exact deterministic report output
- golden assertions for stable codes and counts
- full CI run and revision
