import assert from "node:assert/strict";
import test from "node:test";

import { checkSchemaDrift } from "@hibari/core";
import { schemaFromFormFields } from "../dist/index.js";

const binding = {
  model: "Task",
  app: 42,
  fieldCodes: {
    id: "$id",
    revision: "$revision",
    title: "Title"
  }
};

const introspected = schemaFromFormFields(
  {
    revision: "7",
    properties: {
      Title: {
        type: "SINGLE_LINE_TEXT",
        code: "Title",
        required: true
      },
      BackendOnly: {
        type: "MULTI_LINE_TEXT",
        code: "BackendOnly",
        required: false
      }
    }
  },
  binding
).schema;

const expected = {
  models: [
    {
      name: "Task",
      fields: [
        { kind: "scalar", name: "id", type: "integer", nullable: false, mutable: false },
        { kind: "scalar", name: "revision", type: "integer", nullable: false, mutable: false },
        { kind: "scalar", name: "title", type: "string", nullable: false, mutable: true }
      ],
      identifier: ["id"],
      concurrencyToken: { field: "revision", kind: "revision" }
    }
  ]
};

test("kintone introspection output satisfies backend-neutral schema drift checks", () => {
  const report = checkSchemaDrift(expected, { models: [introspected] });
  assert.deepEqual(report, { compatible: true, diagnostics: [] });
});

test("kintone schema drift is reported by the core contract before record execution", () => {
  const incompatible = {
    models: [
      {
        ...expected.models[0],
        fields: expected.models[0].fields.map((field) =>
          field.name === "title" ? { ...field, type: "number" } : field
        )
      }
    ]
  };

  const report = checkSchemaDrift(incompatible, { models: [introspected] });
  assert.equal(report.compatible, false);
  assert.deepEqual(
    report.diagnostics.map(({ code, path }) => ({ code, path })),
    [{ code: "HIB-SCHEMA-003", path: "models.Task.fields.title" }]
  );
});
