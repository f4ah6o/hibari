# Hibari CI-friendly strict compatibility mode

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Phase

Phase 4: compatibility tooling

## Goal

WordPress compatibility tooling の分類を親 architecture の canonical classification (`native`, `emulated`, `expensive`, `unsupported`) に揃え、同じ machine-readable report を CI から安定した exit code で評価できる policy を追加する。

## Scope

- WordPress `SqlPreflight` の successful classification を `native` に統一する
- compatibility report summary を canonical 4 classes で出力する
- plugin scanner の summary も canonical classes + `uninspectable` に揃える
- default policy: `unsupported` または incomplete/uninspectable を fail、`emulated` / `expensive` は pass
- strict policy: default failures に加え `emulated` / `expensive` も fail
- deterministic JSON output と exit code を返す small CLI wrapper を提供する

## Exit codes

- `0`: policy pass
- `1`: compatibility/policy failure
- `2`: usage/tooling error

## Acceptance criteria

- [ ] WordPress report classifications are `native|emulated|expensive|unsupported`
- [ ] no `portable` classification remains in report output
- [ ] default mode fails unsupported and incomplete scans
- [ ] strict mode additionally fails emulated/expensive classifications
- [ ] strict-mode evaluation does not change runtime/preflight semantics
- [ ] CLI prints deterministic JSON and uses stable exit codes 0/1/2
- [ ] existing stable `HIB-WP-*` diagnostic codes are preserved
- [ ] existing core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- no backend-specific policy
- no new SQL execution support
- no runtime fallback
- no source execution during plugin scan
- no CLI framework dependency

## Completion evidence required

- golden report updates
- policy unit proof for default and strict modes
- CLI exit-code proof
- full CI run and revision
