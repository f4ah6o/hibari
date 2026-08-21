# hibari

Hibari is a capability-aware datastore compatibility layer.

Applications use ordinary data-access APIs while Hibari keeps backend-specific details behind adapters and exposes semantic mismatches, unsupported operations, and dangerous execution plans before backend failure.

The first backend proof will target kintone. The first consumer proofs will target Prisma and stock WordPress, but the core contracts are intentionally independent of all three.

## Current scope

The repository currently contains the first architecture slice:

- backend-neutral Schema IR
- minimal Query / Mutation IR
- Capability Manifest
- `native` / `emulated` / `expensive` / `unsupported` planning
- inspectable Execution Plan
- stable core diagnostic codes
- fake-backend contract tests

kintone REST integration, Prisma integration, and WordPress integration are intentionally not part of this slice.

## Development

```sh
npm install
npm test
```

`npm test` builds the TypeScript core and runs the contract suite using Node's built-in test runner.
