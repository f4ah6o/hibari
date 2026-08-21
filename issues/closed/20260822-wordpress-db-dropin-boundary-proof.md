# Hibari WordPress db.php boundary proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Goal

Phase 3 の最初の実装単位として、stock WordPress を fork せず `wp-content/db.php` database drop-in から Hibari consumer adapter を差し込めることを実証する。

この child issue では WordPress Core 全体の永続化を完成させない。まず、WordPress 7.1 の実releaseを使って次を production-shaped evidence として固定する。

1. stock WordPress が custom `db.php` を読み込む
2. MySQL connection を作らず custom `$wpdb` を利用できる
3. Core API が発行する SQL が custom `$wpdb` query boundary を通る
4. consumer-level で明らかに portable でない SQL を backend 実行前に stable diagnostic で reject できる
5. WordPress package に kintone 固有 logic を入れない

## Target

- WordPress 7.1, released 2026-08-19
- stock release tarball; WordPress source is not vendored into Hibari
- PHP database drop-in boundary documented by WordPress Core

WordPress Core loads `wp-includes/class-wpdb.php`, then `wp-content/db.php`, and skips constructing the default MySQL `wpdb` instance when the drop-in has already supplied `$wpdb`.

## Architecture

```text
stock WordPress
      |
  wp-content/db.php
      |
Hibari_WordPress_Wpdb
      |
WordPress SQL preflight
      |
Hibari WordPress bridge contract
      |
(future runtime transport)
      |
@hibari/core <- backend
```

The PHP boundary cannot import the TypeScript core directly. This issue therefore defines only the consumer-side bridge contract. A later child issue will bind that contract to a backend-neutral Hibari runtime transport. It must not call Kintone REST directly.

## Scope

### PHP consumer boundary

- `Hibari_WordPress_Wpdb` extends stock `wpdb`
- constructor does not create a MySQL connection
- inherited `prepare()` / `get_row()` / `get_var()` / `get_results()` can operate through the overridden query boundary
- query result state (`last_result`, `num_rows`, `rows_affected`, `insert_id`, `last_query`) is populated with wpdb-compatible semantics
- escaping needed by `wpdb::prepare()` works without a mysqli handle

### Bridge contract

Backend-neutral PHP contract:

- SQL string
- preflight plan/classification
- rows
- affected count
- insert id

The bridge must not expose Kintone App IDs, field codes, REST paths, revisions, or pagination.

### Early SQL preflight

Initial consumer-level classifier:

- simple SELECT / INSERT / UPDATE / DELETE / REPLACE: portable candidate
- JOIN: unsupported
- GROUP BY / aggregate: unsupported in this initial proof
- transaction statements: unsupported
- DDL/schema statements: unsupported
- subqueries: unsupported
- unknown SQL: unsupported

Stable diagnostics:

- `HIB-WP-SQL-001`
- `HIB-WP-JOIN-001`
- `HIB-WP-AGG-001`
- `HIB-WP-TXN-001`
- `HIB-WP-DDL-001`
- `HIB-WP-SUBQUERY-001`

This is only consumer-level syntax/semantic preflight. Backend-specific Native / Emulated / Expensive / Unsupported planning remains a Hibari runtime responsibility.

### Stock WordPress proof

CI downloads the pinned WordPress 7.1 release and proves:

- `wp-load.php` / `wp-settings.php` boot with `SHORTINIT`
- the Hibari drop-in supplies the global `$wpdb`
- no MySQL connection is created by the Hibari wpdb implementation
- stock Core `get_option()` emits its prepared SQL through the Hibari query boundary
- a recording fake bridge can return the option row and Core returns the expected option value
- an unsupported JOIN is rejected before the bridge receives it

## Acceptance criteria

- [x] WordPress 7.1 is pinned in the stock-Core proof
- [x] `packages/wordpress/db.php` is a valid WordPress database drop-in
- [x] `Hibari_WordPress_Wpdb` can replace the default `$wpdb` without opening MySQL
- [x] wpdb query/result compatibility required by the proof works
- [x] stock WordPress SHORTINIT boot is green
- [x] stock `get_option()` crosses the Hibari query boundary and returns fake persisted data
- [x] unsupported JOIN is rejected before bridge execution with `HIB-WP-JOIN-001`
- [x] WordPress adapter contains no kintone-specific API or mapping logic
- [x] existing core/kintone/Prisma CI remains green

## Completion evidence

- `packages/wordpress/db.php` is loaded as stock WordPress' database drop-in and installs `Hibari\WordPress\HibariWpdb` into the global `$wpdb`.
- `HibariWpdb` deliberately skips `wpdb::__construct()` and therefore does not create a MySQL connection.
- `wpdb::prepare()` uses the drop-in escaping implementation; inherited `get_row()` is exercised by stock Core `get_option()`.
- `get_option('siteurl')` emits the real WordPress options query through the Hibari bridge and returns `https://example.test` from the recording bridge.
- `SELECT ... JOIN ...` is rejected with `HIB-WP-JOIN-001` before bridge execution.
- SQL preflight includes distinct diagnostics for unsupported SQL shape, JOIN, aggregate, transaction, DDL and subquery cases.
- WordPress consumer code has no Kintone App ID, field code, revision, REST path or pagination logic.
- GitHub Actions CI run #51 (`32529253293`) `wordpress-proof` job passed on PHP 8.3.6 against the official WordPress 7.1 release tarball with output `WordPress 7.1 db.php boundary proof: ok`.
- The parallel Node job completed existing core/kintone/Prisma tests successfully, so the PHP consumer proof did not regress prior phases.

## Non-goals

- complete WordPress install
- post/page CRUD
- users/taxonomy/comments
- wp_postmeta/EAV design
- arbitrary plugin SQL
- complete MySQL compatibility
- live kintone credentials
- direct PHP -> Kintone REST implementation
- final cross-language runtime transport
