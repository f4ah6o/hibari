# Hibari WordPress draft / publish state proof

## Status

Open

## Parent

`issues/open/20260821-capability-aware-datastore-compatibility-layer.md`

## Prerequisites

- `issues/closed/20260822-wordpress-post-content-cru-proof.md`
- `issues/closed/20260822-wordpress-postmeta-dynamic-attributes-proof.md`
- `issues/closed/20260823-wordpress-media-metadata-post-dynamic-attributes-proof.md`

## Goal

親issueの Initial WordPress target に明記された `draft / publish state` を、Stock WordPress 7.1 の public Post APIと既存 ordinary `Post` modelだけで実証する。

新しい workflow/state-machine contract を Hibari core に追加せず、WordPress の publish status は ordinary scalar `Post.status` として既存 QueryIR / MutationIR / planner / KintoneBackend を通ることを確認する。

## Design hypothesis

```text
WordPress post_status
  -> existing Post.status

wp_insert_post(draft)
  -> ordinary Post insert

wp_publish_post(id)
  -> ordinary Post status update
```

WordPress lifecycle hooks、notifications、taxonomy defaulting、revision generation等を Hibari の datastore semantics と混同しない。

## First proof scope

Use unchanged stock public APIs:

```text
wp_insert_post(... post_type=page, post_status=draft ...)
get_post(...)
wp_publish_post(...)
get_post(...)
```

Use a built-in `page` fixture so category/tag defaulting is not required by this focused state proof.

Verify:

- draft page is created through the existing Post binding
- first `get_post()` observes `post_status = draft`
- `wp_publish_post()` reaches the existing Post update path
- later `get_post()` observes `post_status = publish`
- Post identity remains stable
- title/content/parent are not changed merely by the status transition
- no new core state-machine abstraction is added
- no kintone App ID / field code leaks into WordPress consumer code

## Lifecycle-hook boundary

This child proves datastore state persistence, not every WordPress publish side effect.

SHORTINIT proof infrastructure may explicitly isolate callbacks for domains already known to be separate, including:

- revision generation
- slug/date maintenance
- notifications
- plugin/theme callbacks

Do not fake their results. If an unavoidable stock callback requires a datastore domain that should be part of publish semantic equivalence, record it as a new boundary instead of silently bypassing it.

## SQL / capability boundary

Reuse the existing proven `wp_posts` insert / ID read / update-by-ID shapes.

Do not add generic workflow SQL or a WordPress-specific status contract to core. If `wp_publish_post()` emits a previously unseen exact stock shape, add only the narrow consumer translation needed to preserve ordinary Post semantics.

## Acceptance criteria

- [ ] no workflow/state-machine concept is added to Hibari core
- [ ] draft/publish is represented by existing `Post.status`
- [ ] stock `wp_insert_post(... draft ...)` creates through KintoneBackend
- [ ] stock `get_post()` observes draft state
- [ ] stock `wp_publish_post()` persists publish state through KintoneBackend
- [ ] later stock `get_post()` observes publish state
- [ ] identity and unrelated Post scalar fields remain stable
- [ ] publish side domains outside datastore-state persistence are explicitly isolated, not emulated
- [ ] all previous core/kintone/Prisma/WordPress proofs remain green

## Guardrails

- do not add a WordPress workflow abstraction to core
- do not implement notification/email behavior
- do not add generic `WP_Query`
- do not add transaction semantics for this child
- do not broaden taxonomy behavior merely to publish a page
- do not require live kintone credentials

## Completion evidence required

- stock WordPress 7.1 draft -> publish API output
- fake Kintone request evidence for Post create/read/status update/read
- proof that identity and unrelated Post fields remain stable
- full CI including every prior proof
- exact revision/run recorded before moving to `issues/closed`
