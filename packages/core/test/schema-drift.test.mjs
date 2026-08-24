import assert from "node:assert/strict";
import test from "node:test";

import { checkSchemaDrift } from "../dist/index.js";

function scalar(name, type, extra = {}) {
  return { kind: "scalar", name, type, ...extra };
}

test("compatible schema ignores backend-only fields and extension metadata", () => {
  const expected = {
    models: [
      {
        name: "User",
        fields: [
          scalar("id", "integer", { nullable: false, mutable: false }),
          scalar("revision", "integer", { nullable: false, mutable: false }),
          scalar("name", "string", { nullable: false, mutable: true })
        ],
        identifier: ["id"],
        concurrencyToken: { field: "revision", kind: "revision" }
      }
    ]
  };
  const actual = {
    models: [
      {
        name: "User",
        fields: [
          scalar("id", "integer", { nullable: false, mutable: false, extensions: { backend: 1 } }),
          scalar("revision", "integer", { nullable: false, mutable: false }),
          scalar("name", "string", { nullable: false, mutable: true }),
          scalar("backendOnly", "string", { nullable: true, mutable: true })
        ],
        identifier: ["id"],
        concurrencyToken: { field: "revision", kind: "revision" },
        extensions: { backend: { revision: "42" } }
      }
    ]
  };

  assert.deepEqual(checkSchemaDrift(expected, actual), {
    compatible: true,
    diagnostics: []
  });
});

test("schema drift diagnostics are stable and follow expected model and field order", () => {
  const expected = {
    models: [
      {
        name: "User",
        fields: [
          scalar("id", "integer", { nullable: false, mutable: false }),
          scalar("email", "string", { nullable: false, mutable: true }),
          {
            kind: "embeddedCollection",
            name: "labels",
            mutable: true,
            fields: [scalar("value", "string", { nullable: false })]
          }
        ],
        identifier: ["id"],
        concurrencyToken: { field: "revision", kind: "revision" }
      },
      {
        name: "AuditLog",
        fields: [scalar("id", "integer")],
        identifier: ["id"]
      }
    ]
  };
  const actual = {
    models: [
      {
        name: "User",
        fields: [
          scalar("id", "string", { nullable: false, mutable: false }),
          scalar("labels", "string")
        ],
        identifier: ["legacyId"],
        concurrencyToken: { field: "etag", kind: "opaque" }
      }
    ]
  };

  const report = checkSchemaDrift(expected, actual);
  assert.equal(report.compatible, false);
  assert.deepEqual(
    report.diagnostics.map(({ code, path }) => ({ code, path })),
    [
      { code: "HIB-SCHEMA-003", path: "models.User.fields.id" },
      { code: "HIB-SCHEMA-002", path: "models.User.fields.email" },
      { code: "HIB-SCHEMA-003", path: "models.User.fields.labels" },
      { code: "HIB-SCHEMA-004", path: "models.User.identifier" },
      { code: "HIB-SCHEMA-005", path: "models.User.concurrencyToken" },
      { code: "HIB-SCHEMA-001", path: "models.AuditLog" }
    ]
  );
  assert.deepEqual(report.diagnostics[0].details, {
    model: "User",
    field: "id",
    aspect: "type",
    expected: "integer",
    actual: "string"
  });
});

test("explicit nullable and mutable expectations participate in drift checks", () => {
  const expected = {
    models: [
      {
        name: "User",
        fields: [scalar("name", "string", { nullable: false, mutable: true })],
        identifier: []
      }
    ]
  };
  const actual = {
    models: [
      {
        name: "User",
        fields: [scalar("name", "string", { nullable: true, mutable: false })],
        identifier: []
      }
    ]
  };

  const report = checkSchemaDrift(expected, actual);
  assert.deepEqual(
    report.diagnostics.map(({ code, details }) => ({ code, aspect: details.aspect })),
    [
      { code: "HIB-SCHEMA-003", aspect: "nullable contract" },
      { code: "HIB-SCHEMA-003", aspect: "mutable contract" }
    ]
  );
});
