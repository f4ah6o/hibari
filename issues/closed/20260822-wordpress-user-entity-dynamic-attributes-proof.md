# Hibari WordPress user entity / Dynamic Attributes proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260822-wordpress-term-entity-creation-uniqueness-proof.md`

## Goal

Stock WordPress 7.1 の user entity と user metadata を、Hibari core に WordPress-specific user abstraction や authentication subsystem を追加せず、既存 SchemaIR / QueryIR / MutationIR と Dynamic Attributes で成立させる。

`wp_users` は通常の `User` model、`wp_usermeta` は既存 Dynamic Attributes の `UserMeta` binding として扱う。

## Implemented projection

```text
wp_users.ID                  -> User.id
wp_users.user_login          -> User.login
wp_users.user_pass           -> User.passwordHash
wp_users.user_nicename       -> User.nicename
wp_users.user_email          -> User.email
wp_users.user_url            -> User.url
wp_users.user_registered     -> User.registeredAt
wp_users.user_activation_key -> User.activationKey
wp_users.user_status         -> User.status
wp_users.display_name        -> User.displayName

wp_usermeta.umeta_id   -> DynamicAttribute.id
wp_usermeta.user_id    -> DynamicAttribute.ownerId
wp_usermeta.meta_key   -> DynamicAttribute.key
wp_usermeta.meta_value -> DynamicAttribute.value
```

Postmeta SQL translation was factored into configurable `MetadataSqlTranslator`; PostMeta and UserMeta now use the same WordPress-owned Dynamic Attributes translation rather than copied implementations.

Exact stock User SQL shapes for ID/login/email/nicename lookup and User insert/update lower to ordinary QueryIR / MutationIR. Generic `WP_User_Query`, JOIN, aggregate, transaction, and DDL support were not added.

## Acceptance criteria

- [x] no WordPress user/authentication concept is added to Hibari core
- [x] User is an ordinary backend-neutral model executed via existing QueryIR / MutationIR
- [x] `wp_usermeta` reuses Dynamic Attributes rather than adding a new core metadata model
- [x] plain-text password never reaches Hibari runtime/backend/request logs
- [x] stock `wp_insert_user()` creates one User through KintoneBackend
- [x] stock ID/login/email reads observe backend state
- [x] stock `wp_update_user()` persists a basic User field update
- [x] WordPress-generated password hash is preserved opaquely without Hibari interpreting it
- [x] stock default/selected user metadata is persisted and readable through Dynamic Attributes
- [x] duplicate login returns `existing_user_login` without a second User write
- [x] duplicate email returns `existing_user_email` without a second User write
- [x] arbitrary `WP_User_Query`, JOIN, aggregate, transaction, and DDL remain unsupported unless already proven elsewhere
- [x] WordPress/core contain no kintone App ID / field code
- [x] previous core/kintone/Prisma/WordPress proofs remain green

## Completion evidence

Merge-candidate implementation revision before documentation close:

- `f1ded50945e39bdc2c29bd06871e95b2e998810e`
- CI #182 / run `32579212411`
- 9/9 jobs green, including all previous proofs
- stock proof output: `WordPress user entity + Dynamic Attributes -> Hibari -> KintoneBackend proof: ok`

The redacted fake Kintone request evidence demonstrates:

- bounded `User_login = "hibari_user"` lookup
- bounded `User_email = "hibari.user@example.test"` lookup
- one and only one User create on app 90 after duplicate login/email attempts
- User update through the backend path
- UserMeta owner-scoped reads on app 91
- `nickname=Hibari Nick` and stock default metadata persisted through the same Dynamic Attributes path used by postmeta
- `User_pass` replaced with `[REDACTED]` before request evidence is written
- fixture plaintext values and password-hash signatures are rejected by the evidence runner if they appear in logs

The PHP proof additionally reads the persisted `user_pass` through stock `WP_User` and verifies it is non-empty, differs from the supplied plaintext, and is preserved unchanged by the basic user update. Hibari does not inspect or verify the hash.

`wp_maybe_update_user_counts()` is intentionally removed from the focused proof harness. Its `COUNT(*)` maintenance is an aggregate side domain; aggregate SQL remains unsupported instead of being enabled as a hidden prerequisite for User entity compatibility.

## Security boundary

This is a datastore compatibility proof, not approval to store production credential hashes in live kintone.

- authentication, cookies, sessions, password verification, reset flows, MFA, capability authorization, and live kintone access-control review remain out of scope
- Hibari does not hash or verify passwords
- only the WordPress-produced opaque hash crosses the datastore runtime boundary
- neither plaintext nor hash material is emitted in CI request evidence

## Non-goals

- login/authentication/password verification
- cookies/sessions/application passwords/password reset
- roles/capability authorization behavior beyond metadata rows naturally produced by stock create
- arbitrary `WP_User_Query`
- usermeta `meta_query` / search
- user-count aggregate maintenance
- multisite
- deletion/cascade
- live kintone credentials or production security approval
