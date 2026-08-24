# Hibari CI-friendly strict compatibility mode

## Status

Closed

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

- [x] WordPress report classifications are `native|emulated|expensive|unsupported`
- [x] no `portable` classification remains in report output
- [x] default mode fails unsupported and incomplete scans
- [x] strict mode additionally fails emulated/expensive classifications
- [x] strict-mode evaluation does not change runtime/preflight semantics
- [x] CLI prints deterministic JSON and uses stable exit codes 0/1/2
- [x] existing stable `HIB-WP-*` diagnostic codes are preserved
- [x] existing core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- no backend-specific policy
- no new SQL execution support
- no runtime fallback
- no source execution during plugin scan
- no CLI framework dependency

## Completion evidence

- implementation revision: `30742b301b013ad8bf833b531187c3aee1ed4fab`
- canonical compatibility golden: `native=1`, `emulated=0`, `expensive=0`, `unsupported=5`
- plugin source golden: `native=1`, `emulated=0`, `expensive=0`, `unsupported=1`, `uninspectable=1`
- default/strict synthetic policy proof confirms warning-only reports pass default and fail strict with reasons `emulated`, `expensive`
- CLI proof confirms deterministic JSON and exit codes `0` pass, `1` compatibility failure, `2` usage/tooling error
- existing `HIB-WP-JOIN-001`, `HIB-WP-AGG-001`, `HIB-WP-TXN-001`, `HIB-WP-DDL-001`, `HIB-WP-SUBQUERY-001`, and `HIB-WP-SCAN-001` are preserved
- stock WordPress 7.1 db.php boundary proof remains green
- GitHub Actions CI run `32708345074` / run #261 completed successfully
- all 16 CI jobs completed with `success`
