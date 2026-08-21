import assert from "node:assert/strict";
import test from "node:test";

import { createRuntimeHttpServer } from "../dist/index.js";

function plan(operation) {
  return {
    operation,
    classification: "native",
    target: operation.model,
    diagnostics: []
  };
}

async function withServer(runtime, callback) {
  const server = createRuntimeHttpServer({ runtime, maxBodyBytes: 256 });
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  assert.ok(address && typeof address === "object");
  try {
    await callback(`http://127.0.0.1:${address.port}`);
  } finally {
    await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
  }
}

test("projects QueryIR over HTTP without consumer or backend concepts", async () => {
  const seen = [];
  const runtime = {
    async query(query) {
      seen.push(query);
      return { records: [{ value: "ok" }], plan: plan(query) };
    },
    async mutate(mutation) {
      return { affected: 1, plan: plan(mutation) };
    }
  };

  await withServer(runtime, async (base) => {
    const operation = {
      kind: "query",
      model: "Option",
      projection: ["value"],
      filter: { op: "eq", field: "name", value: "siteurl" },
      limit: 1
    };
    const response = await fetch(`${base}/v1/query`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(operation)
    });
    assert.equal(response.status, 200);
    const body = await response.json();
    assert.deepEqual(body.records, [{ value: "ok" }]);
    assert.deepEqual(seen, [operation]);
  });
});

test("preserves structured runtime diagnostics", async () => {
  const runtime = {
    async query() {
      const error = new Error("unsupported");
      error.diagnostics = [{
        code: "HIB-LIMIT-001",
        severity: "error",
        operation: "query",
        target: "Option",
        reason: "limit",
        message: "unsupported",
        capability: "query.offset"
      }];
      throw error;
    },
    async mutate(mutation) {
      return { affected: 0, plan: plan(mutation) };
    }
  };

  await withServer(runtime, async (base) => {
    const response = await fetch(`${base}/v1/query`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ kind: "query", model: "Option", limit: 1 })
    });
    assert.equal(response.status, 422);
    const body = await response.json();
    assert.equal(body.error.diagnostics[0].code, "HIB-LIMIT-001");
  });
});

test("rejects oversized request bodies before runtime execution", async () => {
  let calls = 0;
  const runtime = {
    async query(query) {
      calls += 1;
      return { records: [], plan: plan(query) };
    },
    async mutate(mutation) {
      calls += 1;
      return { affected: 0, plan: plan(mutation) };
    }
  };

  await withServer(runtime, async (base) => {
    const response = await fetch(`${base}/v1/query`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ kind: "query", model: "x".repeat(300) })
    });
    assert.equal(response.status, 413);
    assert.equal(calls, 0);
  });
});
