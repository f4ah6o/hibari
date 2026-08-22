import type { CapabilityManifest } from "@hibari/core";

export const kintoneCapabilities: CapabilityManifest = {
  backend: "kintone",
  query: {
    projection: "native",
    filters: {
      eq: "native",
      ne: "native",
      gt: "native",
      gte: "native",
      lt: "native",
      lte: "native",
      in: "native"
    },
    ordering: "native",
    cursor: "emulated",
    offset: {
      support: "native",
      maximum: 10_000,
      warnAt: 5_000
    },
    join: "unsupported",
    aggregate: "unsupported"
  },
  mutation: {
    operations: {
      insert: "native",
      insertMany: "native",
      update: "emulated",
      updateMany: "emulated",
      delete: "emulated",
      upsert: "emulated"
    },
    optimisticConcurrency: "native"
  },
  transaction: {
    atomicBatch: "unsupported",
    interactive: "unsupported"
  },
  dynamicAttributes: {
    ownerKeyLookup: "native",
    ownerKeyValueLookup: "native",
    multiValue: "native",
    uniqueAdd: "emulated",
    scan: "unsupported"
  },
  relationEdges: {
    leftScopedLookup: "native",
    pairLookup: "native",
    multiEdge: "native",
    uniqueAttach: "emulated",
    attach: "native",
    detach: "emulated",
    replace: "emulated",
    scan: "unsupported"
  },
  limits: {
    pageSize: 500,
    batchSize: 100,
    requestConcurrency: 100,
    requestBudget: 10_000,
    requestWarningAt: 1_000
  },
  extensions: {
    cursor: {
      maxActivePerDomain: 10,
      expiresAfterSeconds: 600
    }
  }
};
