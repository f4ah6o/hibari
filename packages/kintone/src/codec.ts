import type { ModelSchema } from "@hibari/core";
import type { KintoneModelBinding, KintoneRecord } from "./types.js";

function fieldCode(binding: KintoneModelBinding, name: string): string {
  if (name === "$id" || name === "$revision") {
    return name;
  }
  return binding.fieldCodes?.[name] ?? name;
}

function fieldName(binding: KintoneModelBinding, code: string): string {
  for (const [name, mappedCode] of Object.entries(binding.fieldCodes ?? {})) {
    if (mappedCode === code) {
      return name;
    }
  }
  return code;
}

function schemaType(schema: ModelSchema | undefined, name: string): string | undefined {
  const field = schema?.fields.find((candidate) => candidate.name === name);
  return field?.kind === "scalar" ? field.type : undefined;
}

function decodeValue(value: unknown, type: string | undefined): unknown {
  if (value === null || value === undefined) {
    return value;
  }
  if ((type === "number" || type === "integer") && typeof value === "string") {
    const parsed = Number(value);
    return Number.isNaN(parsed) ? value : parsed;
  }
  return value;
}

export function decodeKintoneRecord(
  record: KintoneRecord,
  binding: KintoneModelBinding,
  schema?: ModelSchema
): Readonly<Record<string, unknown>> {
  const result: Record<string, unknown> = {};
  for (const [code, wrapped] of Object.entries(record)) {
    const name = fieldName(binding, code);
    result[name] = decodeValue(wrapped.value, schemaType(schema, name));
  }
  return result;
}

export function encodeKintoneRecord(
  record: Readonly<Record<string, unknown>>,
  binding: KintoneModelBinding,
  schema?: ModelSchema
): Readonly<Record<string, { readonly value: unknown }>> {
  const result: Record<string, { readonly value: unknown }> = {};
  for (const [name, value] of Object.entries(record)) {
    const code = fieldCode(binding, name);
    if (code === "$id" || code === "$revision") {
      continue;
    }
    const schemaField = schema?.fields.find((field) => field.name === name);
    if (schemaField?.mutable === false) {
      continue;
    }
    result[code] = { value };
  }
  return result;
}

export function kintoneFieldCode(binding: KintoneModelBinding, name: string): string {
  return fieldCode(binding, name);
}

export function kintoneFieldName(binding: KintoneModelBinding, code: string): string {
  return fieldName(binding, code);
}
