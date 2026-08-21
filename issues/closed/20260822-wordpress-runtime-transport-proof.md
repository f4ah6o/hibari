# Hibari WordPress runtime transport proof

## Status

Closed

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

into a backend-neutral Hibari `QueryIR` targeting application model `Option`.

WordPress table/column mapping is consumer schema knowledge and belongs under `packages/wordpress`, not in core/runtime-http/kintone.

### PHP HTTP bridge

- uses the WordPress SQL preflight first
- translates supported SQL to QueryIR/MutationIR
- invokes generic runtime HTTP endpoints
- maps runtime records back to wpdb-shaped result rows
- translates structured runtime errors into WordPress `CompatibilityException`
- contains no concrete backend API

## Acceptance criteria

- [x] `@hibari/runtime-http` depends only on `@hibari/core`
- [x] HTTP runtime exposes query/mutation endpoints without SQL/WordPress/kintone concepts
- [x] runtime errors preserve structured diagnostics
- [x] WordPress package owns WordPress SQL/table/column translation
- [x] PHP HTTP bridge remains backend-neutral
- [x] stock WordPress 7.1 `get_option()` reaches `KintoneBackend` through the runtime HTTP protocol
- [x] fake Kintone REST observes the translated option query
- [x] unsupported JOIN is rejected before any HTTP runtime request
- [x] existing core/kintone/Prisma/WordPress db.php proofs remain green

## Completion evidence

- `@hibari/runtime-http` is a separate workspace whose production dependency is only `@hibari/core`.
- `/v1/query` and `/v1/mutation` accept backend-neutral QueryIR / MutationIR and have no SQL, WordPress, or Kintone translation logic.
- Runtime HTTP contract tests cover QueryIR projection, structured diagnostic preservation, and request-body size rejection.
- `packages/wordpress/src/WordPressSqlTranslator.php` owns the stock `wp_options` SQL -> `Option` QueryIR mapping.
- `packages/wordpress/src/HttpBridge.php` transports translated IR over HTTP and maps runtime records back to wpdb result columns without concrete backend concepts.
- Full proof uses stock WordPress 7.1 `get_option('siteurl')` and returns `https://kintone-backed.example.test` from a record supplied by `KintoneBackend`.
- The fake Kintone transport observes one and only one REST request, with `Option_name = "siteurl"` and `Option_value` field projection. The unsupported JOIN produces no additional runtime/backend request.
- GitHub Actions CI run #71 (`32529898550`) completed all three jobs successfully: `test`, `wordpress-proof`, and `wordpress-runtime-proof`.
- `wordpress-runtime-proof` emitted `WordPress -> Hibari runtime -> KintoneBackend proof: ok` on PHP 8.3.6 / Node 22.
- The initial CI failure was a TypeScript `exactOptionalPropertyTypes` construction bug in runtime HTTP error serialization, fixed before the successful proof; no compatibility semantics were weakened.

## Non-goals

- public internet runtime deployment
- authentication / TLS / multi-tenant authorization
- complete WordPress SQL translation
- complete options write support
- posts/pages/users/taxonomy/comments
- wp_postmeta/EAV
- arbitrary plugin SQL
- live kintone credentials
