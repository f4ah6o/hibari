# Hibari WordPress full bootstrap + standard theme proof

## Status

Open

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
- no `SHORTINIT`
- `wp-load.php` / normal `wp-settings.php` completes
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

The proof may disable cron spawning in fixture configuration. It must not stub datastore reads or fake successful SQL results. If normal bootstrap emits a previously unsupported SQL shape, classify whether it belongs to an existing semantic domain; add only the narrow consumer projection required when semantics are already covered, otherwise record the missing domain explicitly.

## Acceptance criteria

- [ ] stock WordPress 7.1 normal bootstrap completes without `SHORTINIT`
- [ ] Hibari `db.php` remains the active `wpdb`
- [ ] no MySQL connection is opened or advertised
- [ ] normal bootstrap reaches `wp_loaded`
- [ ] stock bundled theme is selected through ordinary Options state
- [ ] `wp_get_theme()` resolves an existing bundled theme
- [ ] no Theme concept is added to Hibari core/backend
- [ ] unsupported SQL remains fail-closed rather than silently emulated
- [ ] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not add Theme or template filesystem concepts to core
- do not hard-code a WordPress bundled theme name in production code
- do not enable generic SQL/JOIN/aggregate execution
- do not use `SHORTINIT` in this proof
- do not turn this child into full front-page rendering
- do not require live kintone credentials

## Completion evidence required

- exact stock WordPress 7.1 normal-bootstrap output
- selected bundled theme identity/existence evidence
- fake Kintone request evidence for bootstrap Option reads
- proof that Hibari wpdb remains active and non-MySQL
- full CI including every previous proof
- exact revision/run recorded before moving to `issues/closed`
