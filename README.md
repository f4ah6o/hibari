# hibari

Hibari is a capability-aware datastore compatibility layer.

Applications and existing software should be able to use ordinary data-access APIs without carrying backend-specific App IDs, field codes, pagination rules, or concurrency tokens through application code. Hibari keeps those details behind backend adapters and exposes unsupported or dangerous semantics before a backend failure.

## Architecture

```text
consumer -> @hibari/core <- backend
                         <- @hibari/kintone
```

The core owns backend-neutral Schema / Query / Mutation IR, capability planning, execution plans, and diagnostics. Backends consume those contracts; concrete backend APIs do not flow back into the core.

## Current implementation

### `@hibari/core`

- backend-neutral Schema IR
- minimal Query / Mutation IR
- Capability Manifest
- `native` / `emulated` / `expensive` / `unsupported` planning
- inspectable Execution Plan
- stable structured diagnostics

### `@hibari/kintone`

- form-field introspection into Schema IR
- application field name <-> kintone field code mapping
- primitive record codec with `$id` / `$revision`
- scalar filter / projection / ordering compiler
- cursor and offset pagination without silent 500-record truncation
- early rejection around the 10,000 offset ceiling
- create / batched createMany / update / updateMany / delete / semantic upsert
- optimistic concurrency through `$revision`
- injectable transport and fetch-based REST transport
- kintone limits centralized in one Capability Manifest

Prisma and WordPress are the next consumer proofs; neither is implemented yet.

## Development

```sh
npm install
npm test
```

The test suite builds both workspaces and runs backend-neutral core contracts plus kintone fake-transport contracts. Live kintone credentials are not required for the contract suite.
