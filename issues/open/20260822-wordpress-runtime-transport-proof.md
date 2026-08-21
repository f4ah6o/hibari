# Hibari WordPress runtime transport proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisite

`issues/closed/20260822-wordpress-db-dropin-boundary-proof.md`

## Goal

Stock WordPress 7.1 の `wp-content/db.php` consumer boundary を、concrete backend ではなく backend-neutral Hibari runtime transport へ接続し、実際に `KintoneBackend` まで到達する最初の end-to-end persistence proof を作る。

成功経路:

```text
stock WordPress 7.1
  -> db.php / HibariWpdb
  -> WordPress SQL translator
  -> PHP HTTP bridge
  -> @hibari/runtime-http
  -> @hibari/core DatastoreRuntime
  -> KintoneBackend
  -> fake Kintone REST
```

WordPress PHP code は kintone App ID / field code / REST endpoint / revision / pagination を知らない。`@hibari/runtime-http` は WordPress / SQL / kintone を知らない。

## Scope

### `@hibari/runtime-http`

A minimal backend-neutral HTTP projection of `DatastoreRuntime`:

- `POST /v1/query` accepting `QueryIR`
- `POST /v1/mutation` accepting `MutationIR`
- JSON query/mutation results preserving execution plan and structured diagnostics
- request body size bound
- loopback-oriented proof server API
- depends only on `@hibari/core`
- no SQL parser
- no WordPress concepts
- no kintone concepts

This is an execution protocol proof, not a public internet deployment profile. Authentication/TLS are out of scope and the server must not imply otherwise.

### WordPress SQL translation

Consumer-owned translation of the smallest real Core query required for the proof:

```sql
SELECT option_value
FROM wp_options
WHERE option_name = %s
LIMIT 1
```

into a backend-neutral Hibari `QueryIR` targeting an application model such as `Option`.

WordPress table/column mapping is consumer schema knowledge and belongs under `packages/wordpress`, not in core/runtime-http/kintone.

### PHP HTTP bridge

- uses the WordPress SQL preflight first
- translates supported SQL to QueryIR/MutationIR
- invokes generic runtime HTTP endpoints
- maps runtime records back to wpdb-shaped result rows
- translates structured runtime errors into WordPress `CompatibilityException`
- contains no concrete backend API

### End-to-end proof

Using stock WordPress 7.1 and a fake Kintone transport:

- `get_option('siteurl')` crosses the full path to `KintoneBackend`
- Kintone query uses configured backend field aliases internally
- WordPress receives the expected option value
- application/Core query contains no kintone concepts
- unsupported JOIN still fails before HTTP runtime execution

## Acceptance criteria

- [ ] `@hibari/runtime-http` depends only on `@hibari/core`
- [ ] HTTP runtime exposes query/mutation endpoints without SQL/WordPress/kintone concepts
- [ ] runtime errors preserve structured diagnostics
- [ ] WordPress package owns WordPress SQL/table/column translation
- [ ] PHP HTTP bridge remains backend-neutral
- [ ] stock WordPress 7.1 `get_option()` reaches `KintoneBackend` through the runtime HTTP protocol
- [ ] fake Kintone REST observes the translated option query
- [ ] unsupported JOIN is rejected before any HTTP runtime request
- [ ] existing core/kintone/Prisma/WordPress db.php proofs remain green

## Non-goals

- public internet runtime deployment
- authentication / TLS / multi-tenant authorization
- complete WordPress SQL translation
- complete options write support
- posts/pages/users/taxonomy/comments
- wp_postmeta/EAV
- arbitrary plugin SQL
- live kintone credentials
