# hibari

Hibari is a capability-aware datastore compatibility layer.

Applications and existing software use ordinary data-access APIs while backend-specific App IDs, field codes, pagination rules, and concurrency tokens stay behind adapters. Unsupported or dangerous semantics are rejected before they become backend failures.

## Architecture

```text
consumer ----------------> @hibari/core <---------------- backend
   |                            ^                            |
   |                            |                            |
@hibari/prisma                  |                    @hibari/kintone
WordPress db.php -> runtime HTTP+
```

Consumers and backends depend on backend-neutral contracts. Consumer packages do not contain concrete backend APIs.

## Current implementation

### `@hibari/core`

- backend-neutral Schema / Query / Mutation IR
- backend-neutral `DatastoreRuntime`
- Capability Manifest
- `native` / `emulated` / `expensive` / `unsupported` planning
- inspectable Execution Plan
- stable structured diagnostics

### `@hibari/kintone`

- form-field introspection into Schema IR
- application field names mapped to kintone field codes
- kintone `$id` / `$revision` can stay hidden behind application aliases
- scalar filter / projection / ordering compiler
- cursor and offset pagination without silent 500-record truncation
- early rejection around the 10,000 offset ceiling
- create / batched createMany / update / updateMany / delete / semantic upsert
- optimistic concurrency through kintone revision
- injectable transport and fetch-based REST transport
- kintone limits centralized in one Capability Manifest

### `@hibari/prisma`

- Prisma ORM 7.9.1 driver adapter
- ordinary generated `PrismaClient` CRUD
- deterministic SQLite-shaped SQL subset -> Hibari IR
- SELECT / INSERT / UPDATE / DELETE / RETURNING
- stable early errors for unsupported JOIN / aggregate / transaction / schema SQL
- no dependency on `@hibari/kintone`

The integration suite proves this path end to end without live credentials:

```text
generated PrismaClient
  -> @hibari/prisma
  -> @hibari/core
  -> KintoneBackend
  -> fake kintone REST
```

Application CRUD in that proof contains no kintone App ID, field code, `$id`, `$revision`, REST endpoint, or pagination logic.

### `@hibari/runtime-http`

- backend-neutral HTTP projection of `DatastoreRuntime`
- `POST /v1/query` and `POST /v1/mutation`
- structured diagnostics preserved across the transport
- bounded request bodies
- no SQL, WordPress, or concrete backend logic
- proof-oriented loopback boundary; authentication/TLS/public deployment are intentionally not implied

### WordPress database drop-in

The WordPress consumer is proven against the stock WordPress 7.1 release without forking WordPress:

- `wp-content/db.php` installs `Hibari\WordPress\HibariWpdb`
- the custom `wpdb` does not open a MySQL connection
- inherited WordPress APIs such as `prepare()` / `get_row()` / `update()` / `delete()` continue through the custom query boundary without MySQL metadata access
- WordPress owns WordPress SQL/table/column -> Hibari IR translation
- JOIN / aggregate / transaction / DDL / subquery SQL is rejected early with stable `HIB-WP-*` diagnostics
- the PHP bridge contains no kintone App ID, field code, revision, REST endpoint, or pagination logic

The runtime integration path is:

```text
stock WordPress 7.1
  -> db.php / HibariWpdb
  -> WordPress SQL translator
  -> PHP HTTP bridge
  -> @hibari/runtime-http
  -> @hibari/core
  -> KintoneBackend
  -> fake kintone REST
```

Stock WordPress Options CRUD is also proven through that path:

```text
get_option()
update_option()
add_option()
delete_option()
```

`wp_options.option_name` is modeled as the backend-neutral unique `Option.name` field. Core's MySQL-specific `INSERT ... ON DUPLICATE KEY UPDATE` statement is normalized inside the WordPress consumer to Hibari `upsert`; generic MySQL compatibility is not added to the core or backend.

The next WordPress proof moves to posts/pages content CRUD, followed by users, taxonomy, comments, and postmeta/EAV.

## Development

```sh
npm install
npm test
```

`npm test` runs core, kintone, Prisma, runtime HTTP, and cross-package contracts. CI additionally downloads pinned stock WordPress 7.1 and runs the database-drop-in, runtime-to-KintoneBackend, and Options CRUD proofs. Live kintone credentials are not required.
