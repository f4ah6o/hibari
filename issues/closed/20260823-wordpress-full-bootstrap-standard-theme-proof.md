# Hibari WordPress full bootstrap + standard theme proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-options-crud-proof.md`
- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-taxonomy-relation-projection-proof.md`
- `issues/closed/20260822-wordpress-user-entity-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-comment-entity-dynamic-attributes-proof.md`
- `issues/closed/20260823-wordpress-media-metadata-post-dynamic-attributes-proof.md`
- `issues/closed/20260823-wordpress-draft-publish-state-proof.md`
- `issues/closed/20260823-wordpress-tag-taxonomy-proof.md`

## Goal

これまでのWordPress proofは意図的に `SHORTINIT=true` で個別public API/domainを分離してきた。親issueの Initial WordPress target にある `stock WordPress Core` と `standard theme` を次の統合段階として、Stock WordPress 7.1 の通常 `wp-settings.php` bootstrapを `SHORTINIT` なしで完走し、tarball同梱の標準themeをWordPress自身が解決できることを実証する。

このchildはページ描画や任意 `WP_Query` compatibilityを完成させるものではない。まず通常bootstrapそのものが既存contractsでどこまで成立するかを測る。

## Design hypothesis

```text
stock WordPress 7.1 normal bootstrap
  -> wp-content/db.php / HibariWpdb
  -> ordinary Options and existing domain contracts
  -> runtime HTTP
  -> @hibari/core
  -> KintoneBackend

bundled stock theme selection
  -> ordinary WordPress options
  -> WordPress filesystem/theme APIs
```

ThemeはHibari core/backend conceptではない。theme filesはstock WordPress tarballに存在し、Hibariはtheme selectionに必要なordinary datastore valuesだけを供給する。

## First proof scope

- exact stock WordPress 7.1 tarball
- no WordPress fork
- normal `wp-load.php` / `wp-settings.php` bootstrap; stock WordPress itself defines `SHORTINIT=false`
- `db.php` remains `Hibari\\WordPress\\HibariWpdb`
- no MySQL connection is opened or advertised
- bundled theme slug is discovered from the stock tarball by proof infrastructure rather than hard-coded into Hibari production code
- ordinary Option backend state supplies the selected `template` / `stylesheet` plus minimal site options needed by normal bootstrap
- `wp_get_theme()` resolves an existing bundled theme
- `wp_loaded` is reached
- no page/template rendering is required yet

## Boundaries

Out of scope for this child:

- front-page HTML rendering
- arbitrary `WP_Query`
- arbitrary `WP_Term_Query` / `WP_User_Query` / `WP_Comment_Query`
- plugin loading beyond stock empty/default state
- login/authentication/session behavior
- cron execution / outbound HTTP
- theme customization writes
- filesystem abstraction in Hibari
- live kintone credentials

The proof disables cron spawning in fixture configuration. WordPress normal bootstrap still schedules ordinary cron Option state; those writes are exercised through the already-proven Options CRUD contract rather than stubbed. The proof does not fake datastore reads or successful SQL results.

## Narrow compatibility additions discovered by the proof

Normal WordPress 7.1 bootstrap exposed two existing-domain gaps rather than a new datastore domain:

1. `wp_prime_option_caches()` emits a bounded multi-option read:

   ```text
   SELECT option_name, option_value
   FROM wp_options
   WHERE option_name IN (...)
   ```

   The WordPress consumer translates only this exact semantic shape to an ordinary `Option` QueryIR with an `in` filter. This does not enable generic SQL or JOIN execution.

2. Core block/theme discovery stores large serialized values in ordinary transient Options. Applying the previous regex-based SQL-literal decoder to those large opaque values was brittle. The WordPress consumer now decodes quoted literals with a linear scanner and reverses only the escape forms emitted by `HibariWpdb::_real_escape()` / `addslashes()` (`\\0`, `\\"`, `\\'`, `\\\\`). The serialized payload remains opaque; Hibari does not parse WordPress serialization or theme metadata.

No Theme concept, filesystem abstraction, JOIN engine, aggregate engine, or WordPress-specific backend contract was added to Hibari core.

## Acceptance criteria

- [x] stock WordPress 7.1 normal bootstrap completes without short-circuiting through `SHORTINIT=true`
- [x] stock WordPress normal constants resolve `SHORTINIT === false`
- [x] Hibari `db.php` remains the active `wpdb`
- [x] no MySQL connection is opened or advertised
- [x] normal bootstrap reaches `wp_loaded`
- [x] stock bundled theme is selected through ordinary Options state
- [x] `wp_get_theme()` resolves an existing bundled theme
- [x] no Theme concept is added to Hibari core/backend
- [x] unsupported SQL remains fail-closed rather than silently emulated
- [x] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not add Theme or template filesystem concepts to core
- do not hard-code a WordPress bundled theme name in production code
- do not enable generic SQL/JOIN/aggregate execution
- do not use `SHORTINIT=true` in this proof
- do not turn this child into full front-page rendering
- do not require live kintone credentials

## Completion evidence

- proof branch revision: `42f272b19116f692ef0dcfbe645ddd70f3c591e9`
- PR merge-test revision used by CI: `e23658e34960908919cc25bdb0750cfcb0af93c8`
- CI #231 / run `32611567141`: success, 14/14 jobs green
- `wordpress-full-bootstrap-proof` job `97125376520`: success
- exact proof output:
  - `WordPress full bootstrap + bundled theme -> Hibari -> KintoneBackend proof: ok`
  - `theme: twentytwentyfive`
- the bundled theme name is discovered from the exact stock WordPress 7.1 tarball at proof time; it is not hard-coded into Hibari production code
- proof assertions confirm:
  - stock normal bootstrap defines `SHORTINIT === false`
  - `$GLOBALS['wpdb']` remains `Hibari\\WordPress\\HibariWpdb`
  - `wpdb->is_mysql === false`
  - `did_action('wp_loaded') > 0`
  - Hibari-backed `template` and `stylesheet` Options equal the discovered bundled theme slug
  - `wp_get_theme()` exists and resolves the same stylesheet and template directory
  - `siteurl` remains readable through Hibari-backed Options
- fake Kintone request evidence uses only Option app 84 for app-scoped requests, including:
  - autoload preload through `Autoload in ("yes", "on", "auto-on", "auto")`
  - bounded `option_name IN (...)` cache-prime reads
  - ordinary single Option reads
  - ordinary Option inserts/updates emitted by stock bootstrap lifecycle
  - large `_transient_wp_core_block_css_files` and stock theme-pattern transient values stored opaquely through the same Option model
- no non-Option Kintone app is touched by this integration proof
- arbitrary JOIN / aggregate / transaction / DDL / subquery SQL remains fail-closed
- front-page rendering and broader `WP_Query` compatibility remain separate work
- CI `npm install` still reports 3 high severity vulnerabilities; this pre-existing audit finding is unrelated to this child and remains unresolved
