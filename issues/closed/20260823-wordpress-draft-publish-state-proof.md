# Hibari WordPress draft / publish state proof

## Status

Closed

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260823-wordpress-media-metadata-post-dynamic-attributes-proof.md`

## Goal

親issueの Initial WordPress target に明記された `draft / publish state` を、Stock WordPress 7.1 の public Post APIと既存 ordinary `Post` modelだけで実証する。

新しい workflow/state-machine contract を Hibari core に追加せず、WordPress の publish status は ordinary scalar `Post.status` として既存 QueryIR / MutationIR / planner / KintoneBackend を通ることを確認した。

## Proven model

```text
WordPress post_status
  -> existing Post.status

wp_insert_post(draft)
  -> ordinary Post insert

wp_publish_post(id)
  -> ordinary Post status update
```

## Proven public API path

Stock WordPress 7.1 の以下を変更せず利用した。

```text
wp_insert_post(... post_type=page, post_status=draft ...)
get_post(...)
wp_publish_post(...)
get_post(...)
```

Built-in `page` fixtureを使用し、category/tag defaultingをこのfocused state proofへ持ち込まなかった。

Verified:

- draft page is created through the existing Post binding
- first `get_post()` observes `post_status = draft`
- `wp_publish_post()` reaches the existing Post update path
- later `get_post()` observes `post_status = publish`
- Post identity remains stable
- title/content/type/parent remain stable across the state transition
- no workflow/state-machine abstraction was added to core
- no kintone App ID / field code leaked into WordPress consumer code

## Lifecycle-hook boundary

The first stock run proved an important ordering fact: `wp_publish_post()` persisted `Post_status = publish` to app 85 before the proof failed in the later default lifecycle callback `_transition_post_status()` because SHORTINIT had intentionally not loaded `get_the_guid()`.

The focused proof therefore removes only the stock Core side-effect callbacks registered by `default-filters.php` that belong to separate lifecycle domains:

- `transition_post_status` -> `_transition_post_status` at priority 5
- `transition_post_status` -> `_update_term_count_on_transition_post_status` at priority 10
- `publish_page` -> `_delete_option_fresh_site` at priority 0

Existing revision and changed-slug/date callbacks remain isolated as in the earlier Post proof.

No fake lifecycle result is returned. This child proves datastore state persistence, not GUID/cache/term-count/fresh-site side effects, notifications, plugin/theme callbacks, or revision semantics.

## SQL / capability result

No new production SQL translation or core capability was required. The existing proven `wp_posts` insert / ID read / update-by-ID path handled the transition as an ordinary `Post.status` mutation.

## Acceptance criteria

- [x] no workflow/state-machine concept is added to Hibari core
- [x] draft/publish is represented by existing `Post.status`
- [x] stock `wp_insert_post(... draft ...)` creates through KintoneBackend
- [x] stock `get_post()` observes draft state
- [x] stock `wp_publish_post()` persists publish state through KintoneBackend
- [x] later stock `get_post()` observes publish state
- [x] identity and unrelated Post scalar fields remain stable
- [x] publish side domains outside datastore-state persistence are explicitly isolated, not emulated
- [x] all previous core/kintone/Prisma/WordPress proofs remain green

## Completion evidence

Implementation/proof revision:

- `bd5196d95c664b071a2e9be6f8d9938584dfba00`

GitHub Actions:

- CI #211
- run id `32607003537`
- result: success
- all 12/12 jobs green
- draft/publish job id `97113426155`
- output: `WordPress draft -> publish state -> Hibari -> KintoneBackend proof: ok`

Fake Kintone evidence at the same revision confirms:

1. app 85 creates one Post with `Post_status = draft`, title `Hibari publish-state page`, content `Hibari publish-state body`, `Post_type = page`, and parent 0
2. app 85 reads the same `$id = 1`
3. `wp_publish_post()` resolves the same identity/revision and emits one update containing only `Post_status = publish`
4. later app 85 reads the same `$id = 1`
5. stock API assertions confirm title/content/type/parent and identity did not change

## Guardrails preserved

- no WordPress workflow abstraction added to core
- no notification/email behavior added
- no generic `WP_Query` added
- no transaction semantics added
- taxonomy behavior was not broadened merely to publish a page
- no live kintone credentials required
