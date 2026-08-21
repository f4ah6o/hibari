import assert from "node:assert/strict";
import test from "node:test";

import { KintoneBackend } from "../dist/index.js";

class FakeTransport {
  requests = [];
  responses = [];

  constructor(responses) {
    this.responses = [...responses];
  }

  async request(request) {
    this.requests.push(request);
    const response = this.responses.shift();
    if (response === undefined) {
      throw new Error(`Unexpected request: ${request.method} ${request.path}`);
    }
    return response;
  }
}

test("kintone system fields can be hidden behind application aliases", async () => {
  const transport = new FakeTransport([
    {
      revision: "4",
      properties: {
        Name: { type: "SINGLE_LINE_TEXT", code: "Name", required: true }
      }
    },
    { id: "12", revision: "3" }
  ]);
  const backend = new KintoneBackend(transport, [
    {
      model: "User",
      app: 42,
      fieldCodes: {
        id: "$id",
        revision: "$revision",
        name: "Name"
      }
    }
  ]);

  const introspection = await backend.introspect("User");
  assert.deepEqual(introspection.schema.identifier, ["id"]);
  assert.deepEqual(introspection.schema.concurrencyToken, {
    field: "revision",
    kind: "revision"
  });
  assert.ok(introspection.schema.fields.some((field) => field.name === "id"));
  assert.ok(introspection.schema.fields.some((field) => field.name === "revision"));
  assert.equal(introspection.schema.fields.some((field) => field.name === "$id"), false);

  const prepared = backend.prepareQuery({
    kind: "query",
    model: "User",
    projection: ["id", "name"],
    ordering: [{ field: "id", direction: "asc" }],
    limit: 1
  });
  assert.deepEqual(prepared.compilation.fields, ["$id", "Name"]);
  assert.match(prepared.compilation.query, /order by \$id asc/);

  const inserted = await backend.mutate({
    kind: "mutation",
    operation: "insert",
    model: "User",
    record: { id: 999, revision: 999, name: "Alice" }
  });

  assert.deepEqual(transport.requests[1].body.record, {
    Name: { value: "Alice" }
  });
  assert.deepEqual(inserted.records, [
    { id: 12, revision: 3, name: "Alice" }
  ]);
});
