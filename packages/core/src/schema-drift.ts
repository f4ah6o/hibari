import { diagnosticCodes, type Diagnostic } from "./diagnostics.js";
import type { Field, ModelSchema, ScalarField, SchemaIR } from "./schema.js";

export interface SchemaDriftReport {
  readonly compatible: boolean;
  readonly diagnostics: readonly Diagnostic[];
}

function sameStrings(left: readonly string[], right: readonly string[]): boolean {
  return left.length === right.length && left.every((value, index) => value === right[index]);
}

function modelPath(model: string): string {
  return `models.${model}`;
}

function fieldPath(model: string, field: string): string {
  return `${modelPath(model)}.fields.${field}`;
}

function pushMissingField(
  diagnostics: Diagnostic[],
  model: string,
  field: string,
  path = fieldPath(model, field)
): void {
  diagnostics.push({
    code: diagnosticCodes.schemaFieldMissing,
    severity: "error",
    operation: "schema.check",
    target: model,
    reason: "expected field is missing from backend schema",
    message: `Expected field '${field}' is missing from model '${model}'.`,
    hint: "Add or map the required backend field before using this schema.",
    path,
    details: { model, field }
  });
}

function pushFieldMismatch(
  diagnostics: Diagnostic[],
  model: string,
  field: string,
  path: string,
  aspect: string,
  expected: unknown,
  actual: unknown
): void {
  diagnostics.push({
    code: diagnosticCodes.schemaFieldIncompatible,
    severity: "error",
    operation: "schema.check",
    target: model,
    reason: "backend field contract does not satisfy expected schema",
    message: `Field '${field}' on model '${model}' has incompatible ${aspect}.`,
    hint: "Align the application schema or backend field contract before execution.",
    path,
    details: { model, field, aspect, expected, actual }
  });
}

function compareScalar(
  diagnostics: Diagnostic[],
  model: string,
  expected: ScalarField,
  actual: ScalarField,
  path: string
): void {
  if (expected.type !== actual.type) {
    pushFieldMismatch(diagnostics, model, expected.name, path, "type", expected.type, actual.type);
  }
  if (expected.nullable !== undefined && expected.nullable !== actual.nullable) {
    pushFieldMismatch(
      diagnostics,
      model,
      expected.name,
      path,
      "nullable contract",
      expected.nullable,
      actual.nullable
    );
  }
  if (expected.mutable !== undefined && expected.mutable !== actual.mutable) {
    pushFieldMismatch(
      diagnostics,
      model,
      expected.name,
      path,
      "mutable contract",
      expected.mutable,
      actual.mutable
    );
  }
}

function compareFields(
  diagnostics: Diagnostic[],
  model: string,
  expectedFields: readonly Field[],
  actualFields: readonly Field[],
  pathPrefix = `${modelPath(model)}.fields`
): void {
  const actualByName = new Map(actualFields.map((field) => [field.name, field]));

  for (const expected of expectedFields) {
    const path = `${pathPrefix}.${expected.name}`;
    const actual = actualByName.get(expected.name);
    if (actual === undefined) {
      pushMissingField(diagnostics, model, expected.name, path);
      continue;
    }

    if (expected.kind !== actual.kind) {
      pushFieldMismatch(
        diagnostics,
        model,
        expected.name,
        path,
        "kind",
        expected.kind,
        actual.kind
      );
      continue;
    }

    if (expected.kind === "scalar" && actual.kind === "scalar") {
      compareScalar(diagnostics, model, expected, actual, path);
      continue;
    }

    if (expected.kind === "embeddedCollection" && actual.kind === "embeddedCollection") {
      if (expected.mutable !== undefined && expected.mutable !== actual.mutable) {
        pushFieldMismatch(
          diagnostics,
          model,
          expected.name,
          path,
          "mutable contract",
          expected.mutable,
          actual.mutable
        );
      }
      compareFields(diagnostics, model, expected.fields, actual.fields, `${path}.fields`);
    }
  }
}

function compareModel(
  diagnostics: Diagnostic[],
  expected: ModelSchema,
  actual: ModelSchema
): void {
  compareFields(diagnostics, expected.name, expected.fields, actual.fields);

  if (!sameStrings(expected.identifier, actual.identifier)) {
    diagnostics.push({
      code: diagnosticCodes.schemaIdentifierMismatch,
      severity: "error",
      operation: "schema.check",
      target: expected.name,
      reason: "backend identifier does not match expected schema",
      message: `Identifier for model '${expected.name}' does not match the expected contract.`,
      hint: "Map the same identifier fields on the backend before execution.",
      path: `${modelPath(expected.name)}.identifier`,
      details: { expected: expected.identifier, actual: actual.identifier }
    });
  }

  if (expected.concurrencyToken !== undefined) {
    const actualToken = actual.concurrencyToken;
    if (
      actualToken === undefined ||
      expected.concurrencyToken.field !== actualToken.field ||
      expected.concurrencyToken.kind !== actualToken.kind
    ) {
      diagnostics.push({
        code: diagnosticCodes.schemaConcurrencyMismatch,
        severity: "error",
        operation: "schema.check",
        target: expected.name,
        reason: "backend concurrency token does not match expected schema",
        message: `Concurrency token for model '${expected.name}' does not match the expected contract.`,
        hint: "Align the backend revision/concurrency mapping before using optimistic concurrency.",
        path: `${modelPath(expected.name)}.concurrencyToken`,
        details: { expected: expected.concurrencyToken, actual: actualToken }
      });
    }
  }
}

export function checkSchemaDrift(expected: SchemaIR, actual: SchemaIR): SchemaDriftReport {
  const diagnostics: Diagnostic[] = [];
  const actualByName = new Map(actual.models.map((model) => [model.name, model]));

  for (const expectedModel of expected.models) {
    const actualModel = actualByName.get(expectedModel.name);
    if (actualModel === undefined) {
      diagnostics.push({
        code: diagnosticCodes.schemaModelMissing,
        severity: "error",
        operation: "schema.check",
        target: expectedModel.name,
        reason: "expected model is missing from backend schema",
        message: `Expected model '${expectedModel.name}' is missing from the backend schema.`,
        hint: "Create or bind the required backend model before execution.",
        path: modelPath(expectedModel.name),
        details: { model: expectedModel.name }
      });
      continue;
    }

    compareModel(diagnostics, expectedModel, actualModel);
  }

  return { compatible: diagnostics.length === 0, diagnostics };
}
