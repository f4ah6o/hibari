import assert from "node:assert/strict";
import test from "node:test";

import {
  KintoneBackend,
  KintoneCompatibilityError,
  compileKintoneQuery,
  decodeKintoneRecord,
  encodeKintoneRecord,
  kintoneCapabilities,
  schemaFromFormFields
} from "../dist/index.js";

class FakeTransport {
  requests = [];
  responses = [];

  constructor(responses = []) {
    this.responses = [...responses];
  }

  async request(request) {
    this.requests.push(request);
    if (this.responses.length === 0) {
      throw new Error(`Unexpected request: ${request.method} ${request.path}`);
    }
    const response = this.responses.shift();
    if (response instanceof Error) throw response;
    return response;
  }
}

const binding = {
  model: "Ticket",
  app: 42,
  fieldCodes: {
    name: "Name",
    score: "Score",
    createdAt: "Created_time",
    slug: "Slug",
    archived: "Archived",
    createdOnly: "Created_only"
  },
  uniqueFields: ["slug"]
};

function field(value, type = "SINGLE_LINE_TEXT") {
  return { type, value };
}

test("kintone capability manifest records official hard limits", () => {
  assert.equal(kintoneCapabilities.limits.pageSize, 500);
  assert.equal(kintoneCapabilities.query.offset.maximum, 10_000);
  assert.equal(kintoneCapabilities.limits.batchSize, 100);
  assert.equal(kintoneCapabilities.limits.requestConcurrency, 100);
  assert.equal(kintoneCapabilities.limits.requestBudget, 10_000);
  assert.equal(kintoneCapabilities.query.join, "unsupported");
  assert.equal(kintoneCapabilities.transaction.interactive, "unsupported");
});

test("form introspection maps identity, revision, unique, subtable, and relation hints", () => {
  const result = schemaFromFormFields(
    {
      revision: "17",
      properties: {
        Name: {
          type: "SINGLE_LINE_TEXT",
          code: "Name",
          label: "Name",
          required: true,
          unique: true
        },
        Score: { type: "NUMBER", code: "Score", required: false },
        Calculated: { type: "CALC", code: "Calculated" },
        Lines: {
          type: "SUBTABLE",
          code: "Lines",
          fields: {
            Description: { type: "SINGLE_LINE_TEXT", code: "Description" },
            Qty: { type: "NUMBER", code: "Qty" }
          }
        },
        CustomerCode: {
          type: "SINGLE_LINE_TEXT",
          code: "CustomerCode",
          lookup: {
            relatedApp: { app: "99", code: "Customer" },
            relatedKeyField: "Code"
          }
        },
        Related: {
          type: "REFERENCE_TABLE",
          code: "Related",
          referenceTable: {
            relatedApp: { app: "100", code: "Comment" },
            condition: { field: "Name", relatedField: "TicketName" }
          }
        }
      }
    },
    binding
  );

  assert.equal(result.revision, "17");
  assert.deepEqual(result.schema.identifier, ["$id"]);
  assert.deepEqual(result.schema.concurrencyToken, { field: "$revision", kind: "revision" });
  assert.ok(result.schema.fields.some((candidate) => candidate.name === "$id"));
  assert.equal(result.schema.fields.find((candidate) => candidate.name === "name")?.nullable, false);
  assert.equal(result.schema.fields.find((candidate) => candidate.name === "Calculated")?.mutable, false);
  assert.deepEqual(result.schema.uniqueConstraints, [{ fields: ["name"] }]);
  const lines = result.schema.fields.find((candidate) => candidate.name === "Lines");
  assert.equal(lines?.kind, "embeddedCollection");
  assert.deepEqual(lines?.fields.map((candidate) => candidate.name), ["Description", "Qty"]);
  assert.ok(result.schema.relationHints?.some((hint) => hint.targetModel === "Customer" && hint.cardinality === "one"));
  assert.ok(result.schema.relationHints?.some((hint) => hint.targetModel === "Comment" && hint.cardinality === "many"));
});

test("primitive record codec hides field codes and converts numeric values using schema", () => {
  const { schema } = schemaFromFormFields(
    {
      revision: "1",
      properties: {
        Name: { type: "SINGLE_LINE_TEXT", code: "Name" },
        Score: { type: "NUMBER", code: "Score" }
      }
    },
    binding
  );

  const decoded = decodeKintoneRecord(
    {
      $id: field("12", "__ID__"),
      $revision: field("3", "__REVISION__"),
      Name: field("alpha"),
      Score: field("42", "NUMBER")
    },
    binding,
    schema
  );
  assert.deepEqual(decoded, { $id: 12, $revision: 3, name: "alpha", score: 42 });

  const encoded = encodeKintoneRecord(
    { $id: 12, $revision: 3, name: "beta", score: 7 },
    binding,
    schema
  );
  assert.deepEqual(encoded, { Name: { value: "beta" }, Score: { value: 7 } });
});

test("query compiler maps fields, escapes strings, and preserves query option order", () => {
  const compiled = compileKintoneQuery(
    {
      kind: "query",
      model: "Ticket",
      projection: ["name", "score"],
      filter: {
        op: "and",
        expressions: [
          { op: "eq", field: "name", value: 'A"B\\C' },
          { op: "gte", field: "score", value: 10 }
        ]
      },
      ordering: [{ field: "createdAt", direction: "desc" }],
      limit: 100,
      offset: 25
    },
    binding
  );

  assert.equal(
    compiled.query,
    '(Name = "A\\"B\\\\C" and Score >= 10) order by Created_time desc limit 100 offset 25'
  );
  assert.deepEqual(compiled.fields, ["Name", "Score"]);
  assert.equal(compiled.strategy, "records");
});

test("large reads transparently use the cursor API and stop at the requested limit", async () => {
  const first = Array.from({ length: 500 }, (_, index) => ({
    $id: field(String(index + 1), "__ID__"),
    Name: field(`ticket-${index + 1}`)
  }));
  const second = Array.from({ length: 500 }, (_, index) => ({
    $id: field(String(index + 501), "__ID__"),
    Name: field(`ticket-${index + 501}`)
  }));
  const transport = new FakeTransport([
    { id: "cursor-1", totalCount: "1000" },
    { records: first, next: true },
    { records: second, next: true },
    {}
  ]);
  const backend = new KintoneBackend(transport, [binding]);

  const result = await backend.query({
    kind: "query",
    model: "Ticket",
    projection: ["$id", "name"],
    ordering: [{ field: "$id", direction: "asc" }],
    limit: 700
  });

  assert.equal(result.records.length, 700);
  assert.equal(result.records[0].name, "ticket-1");
  assert.equal(result.records[699].name, "ticket-700");
  assert.deepEqual(
    transport.requests.map(({ method, path }) => [method, path]),
    [
      ["POST", "/k/v1/records/cursor.json"],
      ["GET", "/k/v1/records/cursor.json"],
      ["GET", "/k/v1/records/cursor.json"],
      ["DELETE", "/k/v1/records/cursor.json"]
    ]
  );
  assert.equal(transport.requests[0].body.size, 500);
});

test("insertMany is transparently chunked to the Kintone 100-record write limit", async () => {
  const transport = new FakeTransport([
    { ids: Array.from({ length: 100 }, (_, i) => String(i + 1)), revisions: Array(100).fill("1") },
    { ids: Array.from({ length: 100 }, (_, i) => String(i + 101)), revisions: Array(100).fill("1") },
    { ids: ["201", "202", "203", "204", "205"], revisions: Array(5).fill("1") }
  ]);
  const backend = new KintoneBackend(transport, [binding]);

  const result = await backend.mutate({
    kind: "mutation",
    operation: "insertMany",
    model: "Ticket",
    records: Array.from({ length: 205 }, (_, index) => ({ name: `ticket-${index}` }))
  });

  assert.equal(result.affected, 205);
  assert.equal(result.plan.estimatedRequests, 3);
  assert.deepEqual(
    transport.requests.map((request) => request.body.records.length),
    [100, 100, 5]
  );
});

test("update resolves the selector before write and sends Kintone revision concurrency", async () => {
  const transport = new FakeTransport([
    {
      records: [
        { $id: field("7", "__ID__"), $revision: field("12", "__REVISION__") }
      ]
    },
    { records: [{ id: "7", revision: "13" }] }
  ]);
  const backend = new KintoneBackend(transport, [binding]);

  const result = await backend.mutate({
    kind: "mutation",
    operation: "update",
    model: "Ticket",
    where: { op: "eq", field: "slug", value: "alpha" },
    changes: { archived: true },
    concurrency: { field: "$revision", expected: 12 }
  });

  assert.equal(result.affected, 1);
  assert.equal(transport.requests[0].method, "GET");
  assert.equal(transport.requests[1].method, "PUT");
  assert.deepEqual(transport.requests[1].body.records, [
    { id: "7", record: { Archived: { value: true } }, revision: 12 }
  ]);
});

test("upsert rejects a non-unique selector before any backend request", async () => {
  const transport = new FakeTransport([]);
  const backend = new KintoneBackend(transport, [binding]);

  await assert.rejects(
    () =>
      backend.mutate({
        kind: "mutation",
        operation: "upsert",
        model: "Ticket",
        where: { op: "eq", field: "name", value: "alpha" },
        create: { name: "alpha" },
        update: { archived: false }
      }),
    (error) => {
      assert.ok(error instanceof KintoneCompatibilityError);
      assert.equal(error.diagnostics[0].code, "HIB-KINTONE-UPSERT-001");
      return true;
    }
  );
  assert.equal(transport.requests.length, 0);
});

test("upsert preserves create/update semantics instead of overwriting with a merged record", async () => {
  const transport = new FakeTransport([
    {
      records: [
        { $id: field("9", "__ID__"), $revision: field("2", "__REVISION__") }
      ]
    },
    { revision: "3" }
  ]);
  const backend = new KintoneBackend(transport, [binding]);

  await backend.mutate({
    kind: "mutation",
    operation: "upsert",
    model: "Ticket",
    where: { op: "eq", field: "slug", value: "stable" },
    create: { slug: "stable", createdOnly: "new-only" },
    update: { archived: true }
  });

  assert.equal(transport.requests[1].path, "/k/v1/record.json");
  assert.deepEqual(transport.requests[1].body.record, { Archived: { value: true } });
  assert.equal("Created_only" in transport.requests[1].body.record, false);
});

test("finite offset reads above 500 records page without silently truncating", async () => {
  const first = Array.from({ length: 500 }, (_, index) => ({
    $id: field(String(index + 101), "__ID__"),
    Name: field(`ticket-${index + 101}`)
  }));
  const second = Array.from({ length: 200 }, (_, index) => ({
    $id: field(String(index + 601), "__ID__"),
    Name: field(`ticket-${index + 601}`)
  }));
  const transport = new FakeTransport([
    { records: first },
    { records: second }
  ]);
  const backend = new KintoneBackend(transport, [binding]);

  const result = await backend.query({
    kind: "query",
    model: "Ticket",
    ordering: [{ field: "$id", direction: "asc" }],
    offset: 100,
    limit: 700
  });

  assert.equal(result.records.length, 700);
  assert.equal(result.plan.estimatedRequests, 2);
  assert.match(transport.requests[0].body.query, /limit 500 offset 100$/);
  assert.match(transport.requests[1].body.query, /limit 200 offset 600$/);
});

test("offset pagination that would cross 10000 is rejected before transport", async () => {
  const transport = new FakeTransport([]);
  const backend = new KintoneBackend(transport, [binding]);

  await assert.rejects(
    () =>
      backend.query({
        kind: "query",
        model: "Ticket",
        offset: 9_900,
        limit: 700
      }),
    (error) => {
      assert.ok(error instanceof KintoneCompatibilityError);
      assert.equal(error.diagnostics[0].code, "HIB-KINTONE-OFFSET-002");
      return true;
    }
  );
  assert.equal(transport.requests.length, 0);
});
