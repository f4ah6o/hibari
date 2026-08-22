import assert from "node:assert/strict";
import test from "node:test";

import {
  HibariPlanningError,
  assertExecutable,
  lowerRelationEdgeOperation,
  planRelationEdgeOperation
} from "../dist/index.js";

const nativeFilters = {
  eq: "native",
  ne: "native",
  gt: "native",
  gte: "native",
  lt: "native",
  lte: "native",
  in: "native"
};

function manifest(overrides = {}) {
  return {
    backend: "fake-record-store",
    query: {
      projection: "native",
      filters: nativeFilters,
      ordering: "native",
      cursor: "native",
      offset: { support: "native", maximum: 10_000 },
      join: "unsupported",
      aggregate: "unsupported"
    },
    mutation: {
      operations: {
        insert: "native",
        insertMany: "native",
        update: "native",
        updateMany: "native",
        delete: "native",
        upsert: "native"
      },
      optimisticConcurrency: "native"
    },
    transaction: { atomicBatch: "native", interactive: "unsupported" },
    relationEdges: {
      leftScopedLookup: "native",
      pairLookup: "native",
      multiEdge: "native",
      uniqueAttach: "native",
      attach: "native",
      detach: "native",
      replace: "native",
      scan: "unsupported",
      ...overrides.relationEdges
    },
    limits: { pageSize: 500, batchSize: 100 }
  };
}

const binding = {
  model: "RelationEdge",
  idField: "id",
  leftField: "leftId",
  rightField: "rightId",
  contextField: "context"
};

test("relation lookup lowers to a left-scoped edge query rather than a JOIN", () => {
  const [query] = lowerRelationEdgeOperation({
    kind: "relationEdge",
    operation: "lookup",
    binding,
    leftId: 42,
    context: "category"
  });

  assert.equal(query.kind, "query");
  assert.equal(query.model, "RelationEdge");
  assert.deepEqual(query.filter, {
    op: "and",
    expressions: [
      { op: "eq", field: "leftId", value: 42 },
      { op: "eq", field: "context", value: "category" }
    ]
  });
});

test("unique attach is an explicit pair existence check plus edge insert", () => {
  const steps = lowerRelationEdgeOperation({
    kind: "relationEdge",
    operation: "attach",
    binding,
    leftId: 42,
    rightId: 7,
    context: "category"
  });

  assert.equal(steps.length, 2);
  assert.equal(steps[0].kind, "query");
  assert.equal(steps[0].limit, 1);
  assert.equal(steps[1].kind, "mutation");
  assert.equal(steps[1].operation, "insert");
  assert.deepEqual(steps[1].record, {
    leftId: 42,
    rightId: 7,
    context: "category"
  });
});

test("kintone-like unique attach and detach semantics stay visible as emulation", () => {
  const attach = planRelationEdgeOperation(
    {
      kind: "relationEdge",
      operation: "attach",
      binding,
      leftId: 42,
      rightId: 7,
      context: "category"
    },
    manifest({ relationEdges: { uniqueAttach: "emulated" } })
  );
  assert.equal(attach.classification, "emulated");
  assert.ok(attach.diagnostics.some(({ code }) => code === "HIB-CAP-002"));
  assert.doesNotThrow(() => assertExecutable(attach));

  const detach = planRelationEdgeOperation(
    {
      kind: "relationEdge",
      operation: "detach",
      binding,
      leftId: 42,
      rightIds: [7],
      context: "category"
    },
    manifest({ relationEdges: { detach: "emulated" } })
  );
  assert.equal(detach.classification, "emulated");
});

test("replace lowers a deterministic current/next set diff", () => {
  const steps = lowerRelationEdgeOperation({
    kind: "relationEdge",
    operation: "replace",
    binding,
    leftId: 42,
    context: "category",
    currentRightIds: [1, 2, 3],
    nextRightIds: [2, 3, 4]
  });

  assert.equal(steps.length, 3);
  assert.equal(steps[0].kind, "mutation");
  assert.equal(steps[0].operation, "delete");
  assert.deepEqual(steps[0].where.expressions.at(-1), {
    op: "in",
    field: "rightId",
    values: [1]
  });
  assert.equal(steps[1].kind, "query");
  assert.equal(steps[2].operation, "insert");
  assert.equal(steps[2].record.rightId, 4);
});

test("relation semantics fail before execution when the backend has no relation profile", () => {
  const withoutRelations = manifest();
  delete withoutRelations.relationEdges;

  const plan = planRelationEdgeOperation(
    {
      kind: "relationEdge",
      operation: "lookup",
      binding,
      leftId: 42
    },
    withoutRelations
  );

  assert.equal(plan.classification, "unsupported");
  assert.ok(plan.diagnostics.some(({ capability }) => capability === "relationEdges"));
  assert.throws(() => assertExecutable(plan), HibariPlanningError);
});
