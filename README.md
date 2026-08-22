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
- generic Dynamic Attributes contract for EAV-like owner/key/multi-value data
- Dynamic Attribute operations lower to the existing Query / Mutation IR and use the same planner and diagnostics
- generic Relation Edge contract for bounded many-to-many membership
- Relation Edge lookup / attach / detach / replace operations lower to the same Query / Mutation IR and planner instead of introducing JOIN execution into core

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
- Dynamic Attributes capability profile: owner/key lookup and multi-value are native, unique-add is explicit emulation, unbounded cross-owner scan is unsupported
- Relation Edge capability profile: left-scoped/pair lookup, multi-edge, and attach are native; unique attach/detach/replace are explicit emulation; unbounded cross-owner scan is unsupported

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
  -> WordPress translation / semantic projection
  -> PHP HTTP bridge
  -> @hibari/runtime-http
  -> @hibari/core
  -> KintoneBackend
  -> fake kintone REST
```

Stock WordPress Options CRUD is proven through that path:

```text
get_option()
update_option()
add_option()
delete_option()
```

`wp_options.option_name` is modeled as the backend-neutral unique `Option.name` field. Core's MySQL-specific `INSERT ... ON DUPLICATE KEY UPDATE` statement is normalized inside the WordPress consumer to Hibari `upsert`; generic MySQL compatibility is not added to the core or backend. The exact WordPress 7.1 autoload preload used by `wp_load_alloptions()` is also projected to an ordinary `Option` QueryIR rather than expanding generic SQL support.

Stock page content create/read/update is also proven through unchanged public APIs:

```text
wp_insert_post()
get_post()
wp_update_post()
```

`wp_posts.ID` is exposed as ordinary `Post.id`; kintone `$id` remains a backend binding detail.

The first postmeta/EAV proof is implemented as generic Dynamic Attributes rather than a `wp_postmeta` table emulator. The stock public Metadata API is proven end to end:

```text
add_post_meta()
get_post_meta()
update_post_meta()
delete_post_meta()
```

The proof preserves multiple values for the same owner/key and WordPress `unique=true` behavior. WordPress-specific SQL remains in the WordPress adapter, Dynamic Attributes lower to ordinary Hibari IR, and the kintone proof stores metadata in a separately bound record app without exposing its App ID or field codes to WordPress or core.

Stock category relationship membership is proven as generic Relation Edges:

```text
term_exists()
wp_set_object_terms()
wp_get_object_terms(..., fields=tt_ids)
wp_remove_object_terms()
```

`WP_Term_Query` normally generates JOIN SQL for taxonomy reads. Hibari does not add JOIN execution to core: the WordPress consumer uses WordPress's `terms_pre_query` semantic short-circuit for the exact supported term-context and object-membership reads, issuing bounded `TermTaxonomy` and `TermRelationship` Hibari queries instead. The simple relationship pair lookup / insert / delete SQL that stock Core emits is translated narrowly in the WordPress adapter. Re-attaching the same object/term is proven not to create a duplicate edge. Arbitrary JOIN SQL remains unsupported.

Stock term creation and duplicate rejection are now proven through unchanged public APIs too:

```text
wp_insert_term("Hibari Category", "category")
term_exists($term_id, "category")
get_term_by("slug", "hibari-category", "category")
wp_insert_term("Hibari Category", "category") // WP_Error('term_exists')
```

`Term` and `TermTaxonomy` remain ordinary backend-neutral models. The two JOIN-shaped confidence/context checks emitted specifically by `wp_insert_term()` are decomposed inside the WordPress consumer into bounded Hibari queries; they do not enable generic JOIN execution. Hierarchical cache regeneration's exact `id=>parent` query is likewise projected from taxonomy-scoped `TermTaxonomy` records. The kintone proof verifies one Term write and one TermTaxonomy write, then rejects the duplicate without a second logical pair. Race-free atomic uniqueness across two backend apps is not claimed.

Stock User entity create/read/update and duplicate checks are also proven:

```text
wp_insert_user(...)
get_user_by("id", ...)
get_user_by("login", ...)
get_user_by("email", ...)
get_user_meta(...)
wp_update_user(...)
```

`wp_users` is projected to an ordinary backend-neutral `User` model. Login, email, ID, and nicename checks are narrow scalar QueryIR operations; insert/update use ordinary MutationIR. Duplicate login and duplicate email are rejected by stock WordPress as `existing_user_login` and `existing_user_email`, and the proof verifies only one User create reaches the backend.

`wp_usermeta` reuses the same Dynamic Attributes contract as postmeta. The WordPress adapter factors the common EAV SQL shapes into a configurable metadata translator, with PostMeta and UserMeta supplying only their table/owner/id/model configuration. Stock default user metadata such as `nickname`, names, editor preferences, capabilities, and user level is persisted through that common path.

WordPress hashes the supplied password before the User write. Hibari treats that stored value as opaque data and does not hash or verify passwords. The proof verifies the persisted value differs from the plaintext and survives a basic update unchanged, while the fake Kintone request evidence replaces the credential field with `[REDACTED]` before logging and fails if fixture plaintext or recognizable password-hash material appears. Authentication, sessions, password verification/reset, authorization behavior, and approval to store production credential hashes in live kintone remain separate security domains. User-count `COUNT(*)` maintenance also remains outside this proof so aggregate SQL stays unsupported.

Stock Comment entity and CommentMeta create/read/update are now proven through the same generic contracts:

```text
wp_insert_comment(..., comment_meta => ...)
get_comment(...)
get_comment_meta(...)
wp_update_comment(..., comment_meta => ...)
```

`wp_comments` is projected to an ordinary backend-neutral `Comment` model using narrow ID lookup / insert / update translation. `wp_commentmeta` is the third consumer of the same configurable Dynamic Attributes translator after PostMeta and UserMeta; it supplies only its table suffix, owner/id columns, model name, and diagnostic code. WordPress may serialize `comment_id` as a quoted numeric SQL literal because it is not one of wpdb's integer field aliases, so the metadata translator normalizes only bare or quoted numeric owner IDs before lowering them to the same integer `ownerId` contract.

The Comment proof deliberately calls `wp_defer_comment_counting(true)` and does not flush it. This isolates comment-count recomputation, which uses aggregate SQL, without returning fake counts or enabling a generic aggregate engine. The narrow metadata `unique=true` existence check remains a bounded Dynamic Attributes lookup; arbitrary aggregate SQL remains unsupported.

Stock media metadata is proven without adding a Media or Attachment contract to Hibari core. WordPress attachments remain ordinary Posts with `post_type = attachment`, and attachment metadata remains the already-proven PostMeta / Dynamic Attributes path:

```text
wp_insert_attachment(...)
get_post(...)
update_attached_file(...)
get_attached_file(...)
wp_update_attachment_metadata(...)
wp_get_attachment_metadata(...)
```

`_wp_attached_file` round-trips as a scalar Dynamic Attribute. Structured `_wp_attachment_metadata` stays WordPress-owned: WordPress serializes the nested PHP array, Hibari stores the resulting value opaquely, and WordPress unserializes it again on read. The proof found and fixed the generic WordPress metadata SQL-literal decoder so it now exactly reverses the `addslashes()` escaping used by `HibariWpdb::_real_escape()`; no media-specific serialized-data parser was added. Binary upload/blob storage, image resizing, EXIF extraction, CDN/object-storage behavior, arbitrary media-library `WP_Query`, and attachment deletion/cascade remain outside this proof.

Remaining WordPress domains include broader `WP_Query` / `WP_Term_Query` / `WP_User_Query` / `WP_Comment_Query` compatibility, termmeta, term-count/user-count/comment-count maintenance, authentication/security acceptance, and deletion/trash semantics.

## Development

```sh
npm install
npm test
```

`npm test` runs core, kintone, Prisma, runtime HTTP, and cross-package contracts. CI additionally downloads pinned stock WordPress 7.1 and runs the database-drop-in, runtime-to-KintoneBackend, Options CRUD, page content CRU, postmeta Dynamic Attributes, taxonomy Relation Edge, term creation/uniqueness, User/UserMeta, Comment/CommentMeta, and media metadata proofs. Live kintone credentials are not required.
