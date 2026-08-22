# Hibari WordPress user entity / Dynamic Attributes proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-term-entity-creation-uniqueness-proof.md`

## Goal

Stock WordPress 7.1 の user entity と user metadata を、Hibari core に WordPress-specific user abstraction や authentication subsystem を追加せず、既存 SchemaIR / QueryIR / MutationIR と Dynamic Attributes で成立させる。

このchildの目的はログイン認証を実装することではない。`wp_users` のidentity / login・email lookup / create・basic update と、`wp_usermeta` のowner-key multi-value semanticsを既存generic contractへ投影できることを実証する。

## Source-derived constraint

Stock `wp_insert_user()` は user rowだけを書いて終わらない。

- `username_exists()` / `email_exists()` で既存Userを検索する
- nicename collisionを検索する
- `wp_users` をinsert/updateする
- 直後に多数の `wp_usermeta` を `update_user_meta()` で永続化する
- passwordはWordPress自身がhashしてから `user_pass` に保存する

したがって proof を `wp_users` table emulatorだけで終わらせない。既存のDynamic Attributesを `wp_usermeta` に再利用する。

## Design hypothesis

新しいcore contractを先に追加しない。

既存:

- SchemaIR / unique constraints
- scalar QueryIR
- insert/update MutationIR
- Dynamic Attributes owner/key/multi-value contract
- capability planner / diagnostics
- runtime HTTP boundary

でまず成立させる。

WordPress consumer側でPostmetaとUsermetaのSQL shapeが実質同型なら、同じgeneric metadata translator/configurationへ収束させる。`PostmetaSqlTranslator` のcopy-paste増殖は避ける。

## Model projection

```text
wp_users
  ID                  -> User.id
  user_login          -> User.login
  user_pass           -> User.passwordHash
  user_nicename       -> User.nicename
  user_email          -> User.email
  user_url            -> User.url
  user_registered     -> User.registeredAt
  user_activation_key -> User.activationKey
  user_status         -> User.status
  display_name        -> User.displayName

wp_usermeta
  umeta_id   -> DynamicAttribute.id
  user_id    -> DynamicAttribute.ownerId
  meta_key   -> DynamicAttribute.key
  meta_value -> DynamicAttribute.value
```

Backend App IDs / field codes remain only in backend fixture/configuration.

## First proof scope

Use unchanged stock public APIs:

```text
wp_insert_user(...)
get_user_by('id', ...)
get_user_by('login', ...)
get_user_by('email', ...)
wp_update_user(...)
get_user_meta(...)
```

Create one user with deterministic fixture values, then verify:

- generated integer identity
- login lookup
- email lookup
- display-name update
- WordPress-generated password hash persisted and later returned as opaque stored data
- default user metadata written through Dynamic Attributes
- selected metadata can be read later through stock Metadata API

Duplicate proof:

- same `user_login` -> `WP_Error('existing_user_login')`
- same non-empty email -> `WP_Error('existing_user_email')`
- duplicate attempts do not create second User rows

## Security boundary

This child is a datastore compatibility proof, not a production security approval.

- plain-text passwords must never be logged or persisted by Hibari
- only the WordPress-produced password hash may cross the datastore boundary
- fake request evidence must redact/avoid printing password hash values if logs are emitted
- authentication, cookies, sessions, password verification, reset flows, MFA, capability authorization, and live kintone access controls are out of scope
- storing WordPress credential hashes in a real kintone deployment requires a separate security review before any production claim

## User query boundary

Support only stock bounded shapes required by the proof, such as:

- ID lookup
- login lookup
- email lookup
- nicename collision lookup

Translate these into ordinary `User` QueryIR. Do not broaden arbitrary `WP_User_Query` / search / role/meta-query compatibility in this child.

## User mutation boundary

Translate exact stock `wp_users` insert/update shapes into ordinary User MutationIR.

Do not add generic MySQL compatibility. Unsupported user-table SQL remains rejected early.

## Usermeta / Dynamic Attributes

Reuse the existing Dynamic Attributes semantics:

- owner/key lookup
- multi-value rows
- insert
- update
- delete
- unique-add where stock Metadata API requires it

Prefer extracting a configurable WordPress metadata SQL translator over duplicating the complete postmeta implementation.

The core contract must continue to know only owner/key/value semantics, not `post_id`, `user_id`, `meta_id`, or WordPress table names.

## Acceptance criteria

- [ ] no WordPress user/authentication concept is added to Hibari core
- [ ] User is an ordinary backend-neutral model executed via existing QueryIR / MutationIR
- [ ] `wp_usermeta` reuses Dynamic Attributes rather than adding a new core metadata model
- [ ] plain-text password never reaches Hibari runtime/backend/request logs
- [ ] stock `wp_insert_user()` creates one User through KintoneBackend
- [ ] stock ID/login/email reads observe backend state
- [ ] stock `wp_update_user()` persists a basic User field update
- [ ] WordPress-generated password hash is preserved opaquely without Hibari interpreting it
- [ ] stock default/selected user metadata is persisted and readable through Dynamic Attributes
- [ ] duplicate login returns `existing_user_login` without a second User write
- [ ] duplicate email returns `existing_user_email` without a second User write
- [ ] arbitrary `WP_User_Query`, JOIN, aggregate, transaction, and DDL remain unsupported unless already proven elsewhere
- [ ] WordPress/core contain no kintone App ID / field code
- [ ] previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not implement authentication/session/cookie behavior
- do not add password hashing or verification to Hibari
- do not log plain-text or hashed credential material in CI evidence
- do not copy PostmetaSqlTranslator wholesale if a small generic WordPress metadata translator can serve both
- do not build arbitrary `WP_User_Query`
- do not add roles/capabilities semantics beyond metadata rows required for stock create proof
- do not add multisite users
- do not add user deletion/cascade
- do not claim live kintone credential-storage security

## Completion evidence required

- stock WordPress 7.1 public user API output
- fake Kintone request evidence with credential fields redacted/omitted
- exactly one User write after duplicate login/email attempts
- Dynamic Attributes evidence for usermeta
- full CI with all previous proofs green
- exact revision/run recorded before moving to `issues/closed`

## Non-goals

- login/authentication/password verification
- cookies/sessions/application passwords/password reset
- roles/capability authorization behavior
- arbitrary `WP_User_Query`
- usermeta meta_query/search
- multisite
- deletion/cascade
- live kintone credentials or production security approval
