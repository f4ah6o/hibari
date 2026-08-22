import assert from "node:assert/strict";
import test from "node:test";

import {
  HibariPlanningError,
  assertExecutable,
  lowerDynamicAttributeOperation,
  planDynamicAttributeOperation,
  planMutation,
  planQuery
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

function fakeManifest(overrides = {}) {
  return {
    backend: "fake-record-store",
    query: {
      projection: "native",
      filters: nativeFilters,
      ordering: "native",
      cursor: "native",
      offset: { support: "native", maximum: 10_000, warnAt: 5_000 },
      join: "unsupported",
      aggregate: "unsupported",
      ...overrides.query
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
      optimisticConcurrency: "native",
      ...overrides.mutation
    },
    transaction: {
      atomicBatch: "native",
      interactive: "unsupported",
      ...overrides.transaction
    },
    dynamicAttributes: {
      ownerKeyLookup: "native",
      ownerKeyValueLookup: "native",
      multiValue: "native",
      uniqueAdd: "native",
      scan: "unsupported",
      ...overrides.dynamicAttributes
    },
    limits: {
      pageSize: 500,
      batchSize: 100,
      requestConcurrency: 10,
      requestBudget: 10_000,
      requestWarningAt: 20,
      ...overrides.limits
    }
  };
}

const metaBinding = {
  model: "DynamicAttribute",
  idField: "id",
  ownerField: "ownerId",
  keyField: "key",
  valueField: "value"
};

test("native query stays backend-transparent while exposing its plan", () => {
  const plan = planQuery(
    {
      kind: "query",
      model: "User",
      projection: ["id", "name"],
      filter: {
        op: "and",
        expressions: [
          { op: "eq", field: "status", value: "active" },
          { op: "gte", field: "age", value: 18 }
        ]
      },
      ordering: [{ field: "createdAt", direction: "desc" }],
      limit: 1_000,
      cursor: { field: "id", value: 100 }
    },
    fakeManifest()
  );

  assert.equal(plan.classification, "native");
  assert.equal(plan.estimatedRequests, 2);
  assert.equal(plan.clientSideEvaluation, false);
  assert.deepEqual(plan.diagnostics, []);
  assert.doesNotThrow(() => assertExecutable(plan));
});

test("emulated capability is explicit without becoming a warning or error", () => {
  const manifest = fakeManifest({
    query: {
      filters: { ...nativeFilters, in: "emulated" }
    }
  });

  const plan = planQuery(
    {
      kind: "query",
      model: "User",
      filter: { op: "in", field: "status", values: ["active", "pending"] }
    },
    manifest
  );

  assert.equal(plan.classification, "emulated");
  assert.equal(plan.clientSideEvaluation, true);
  assert.deepEqual(
    plan.diagnostics.map(({ code, severity }) => ({ code, severity })),
    [{ code: "HIB-CAP-002", severity: "info" }]
  );
});

test("expensive offset is warned before a backend is called", () => {
  const plan = planQuery(
    {
      kind: "query",
      model: "AuditLog",
      offset: 7_500,
      limit: 100
    },
    fakeManifest()
  );

  assert.equal(plan.classification, "expensive");
  assert.equal(plan.diagnostics[0]?.code, "HIB-COST-001");
  assert.equal(plan.diagnostics[0]?.severity, "warning");
  assert.doesNotThrow(() => assertExecutable(plan));
});

test("offset above the backend limit is rejected during planning", () => {
  const plan = planQuery(
    {
      kind: "query",
      model: "AuditLog",
      offset: 10_001
    },
    fakeManifest()
  );

  assert.equal(plan.classification, "unsupported");
  assert.ok(plan.diagnostics.some(({ code }) => code === "HIB-LIMIT-001"));
  assert.throws(() => assertExecutable(plan), HibariPlanningError);
});

test("unsupported filter semantics fail before execution", () => {
  const manifest = fakeManifest({
    query: {
      filters: { ...nativeFilters, gt: "unsupported" }
    }
  });

  const plan = planQuery(
    {
      kind: "query",
      model: "User",
      filter: { op: "gt", field: "score", value: 10 }
    },
    manifest
  );

  assert.equal(plan.classification, "unsupported");
  assert.equal(plan.diagnostics[0]?.code, "HIB-CAP-001");
  assert.throws(() => assertExecutable(plan), HibariPlanningError);
});

test("mutation uses the same capability and diagnostic model", () => {
  const manifest = fakeManifest({
    mutation: {
      operations: {
        insert: "native",
        insertMany: "native",
        update: "native",
        updateMany: "unsupported",
        delete: "native",
        upsert: "native"
      },
      optimisticConcurrency: "native"
    }
  });

  const plan = planMutation(
    {
      kind: "mutation",
      operation: "updateMany",
      model: "User",
      where: { op: "eq", field: "status", value: "inactive" },
      changes: { archived: true }
    },
    manifest
  );

  assert.equal(plan.classification, "unsupported");
  assert.equal(plan.diagnostics[0]?.capability, "mutation.operations.updateMany");
});

test("batching is transparent but request cost can still become expensive", () => {
  const plan = planMutation(
    {
      kind: "mutation",
      operation: "insertMany",
      model: "Event",
      records: Array.from({ length: 2_001 }, (_, id) => ({ id }))
    },
    fakeManifest({ limits: { batchSize: 100, requestWarningAt: 20 } })
  );

  assert.equal(plan.estimatedRequests, 21);
  assert.equal(plan.classification, "expensive");
  assert.ok(plan.diagnostics.some(({ code }) => code === "HIB-COST-002"));
});

test("dynamic attributes preserve multi-value row semantics without inventing a map", () => {
  const first = lowerDynamicAttributeOperation({
    kind: "dynamicAttribute",
    operation: "add",
    binding: metaBinding,
    ownerId: 42,
    key: "label",
    value: "one"
  });
  const second = lowerDynamicAttributeOperation({
    kind: "dynamicAttribute",
    operation: "add",
    binding: metaBinding,
    ownerId: 42,
    key: "label",
    value: "two"
  });

  assert.equal(first.length, 1);
  assert.equal(second.length, 1);
  assert.equal(first[0].operation, "insert");
  assert.equal(second[0].operation, "insert");
  assert.deepEqual(first[0].record, { ownerId: 42, key: "label", value: "one" });
  assert.deepEqual(second[0].record, { ownerId: 42, key: "label", value: "two" });
});

test("unique dynamic attribute add is planned as an explicit existence check plus insert", () => {
  const plan = planDynamicAttributeOperation(
    {
      kind: "dynamicAttribute",
      operation: "add",
      binding: metaBinding,
      ownerId: 42,
      key: "external-id",
      value: "abc",
      unique: true
    },
    fakeManifest({ dynamicAttributes: { uniqueAdd: "emulated" } })
  );

  assert.equal(plan.classification, "emulated");
  assert.ok(plan.assessments.some(({ capability }) => capability === "dynamicAttributes.uniqueAdd"));
  assert.ok(plan.diagnostics.some(({ code }) => code === "HIB-CAP-002"));
  assert.doesNotThrow(() => assertExecutable(plan));
});

test("dynamic attribute semantics fail early when the backend profile does not expose them", () => {
  const manifest = fakeManifest();
  delete manifest.dynamicAttributes;

  const plan = planDynamicAttributeOperation(
    {
      kind: "dynamicAttribute",
      operation: "lookup",
      binding: metaBinding,
      ownerId: 42,
      key: "label"
    },
    manifest
  );

  assert.equal(plan.classification, "unsupported");
  assert.ok(plan.diagnostics.some(({ capability }) => capability === "dynamicAttributes"));
  assert.throws(() => assertExecutable(plan), HibariPlanningError);
});
