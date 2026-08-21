import assert from "node:assert/strict";
import test from "node:test";

import type {
  DatastoreMutationResult,
  DatastoreQueryResult,
  FilterExpression,
  MutationIR,
  QueryIR,
  RecordValue,
  SchemaIR
} from "@hibari/core";
import { PrismaHibari } from "../dist/index.js";
import { PrismaClient } from "./fixtures/prisma/generated/client.ts";

const schema: SchemaIR = {
  models: [
    {
      name: "User",
      fields: [
        { kind: "scalar", name: "id", type: "integer", nullable: false, mutable: false },
        { kind: "scalar", name: "email", type: "string", nullable: false, mutable: true },
        { kind: "scalar", name: "name", type: "string", nullable: true, mutable: true }
      ],
      identifier: ["id"],
      uniqueConstraints: [{ fields: ["email"] }]
    }
  ]
};

function plan(operation: "query" | "mutation", model: string) {
  return {
    operation,
    model,
    classification: "native" as const,
    assessments: [],
    clientSideEvaluation: false,
    diagnostics: []
  };
}

function matches(record: RecordValue, filter: FilterExpression | undefined): boolean {
  if (filter === undefined) {
    return true;
  }
  if (filter.op === "and") {
    return filter.expressions.every((item) => matches(record, item));
  }
  if (filter.op === "or") {
    return filter.expressions.some((item) => matches(record, item));
  }

  const value = record[filter.field];
  if (filter.op === "in") {
    return filter.values.includes(value as never);
  }
  switch (filter.op) {
    case "eq":
      return value === filter.value;
    case "ne":
      return value !== filter.value;
    case "gt":
      return typeof value === "number" && typeof filter.value === "number" && value > filter.value;
    case "gte":
      return typeof value === "number" && typeof filter.value === "number" && value >= filter.value;
    case "lt":
      return typeof value === "number" && typeof filter.value === "number" && value < filter.value;
    case "lte":
      return typeof value === "number" && typeof filter.value === "number" && value <= filter.value;
  }
}

class MemoryRuntime {
  readonly operations: Array<QueryIR | MutationIR> = [];
  #rows: RecordValue[] = [];
  #nextId = 1;

  async query(query: QueryIR): Promise<DatastoreQueryResult> {
    this.operations.push(query);
    let rows = this.#rows.filter((row) => matches(row, query.filter));

    for (const ordering of [...(query.ordering ?? [])].reverse()) {
      rows = [...rows].sort((left, right) => {
        const a = left[ordering.field];
        const b = right[ordering.field];
        const comparison = a === b ? 0 : a === undefined || a === null ? -1 : b === undefined || b === null ? 1 : a < b ? -1 : 1;
        return ordering.direction === "asc" ? comparison : -comparison;
      });
    }

    const offset = query.offset ?? 0;
    rows = rows.slice(offset, query.limit === undefined ? undefined : offset + query.limit);
    return { records: rows, plan: plan("query", query.model) };
  }

  async mutate(mutation: MutationIR): Promise<DatastoreMutationResult> {
    this.operations.push(mutation);

    if (mutation.operation === "insert") {
      const record = { id: this.#nextId++, ...mutation.record };
      this.#rows.push(record);
      return {
        affected: 1,
        records: [record],
        ids: [String(record.id)],
        plan: plan("mutation", mutation.model)
      };
    }

    if (mutation.operation === "insertMany") {
      const records = mutation.records.map((value) => ({ id: this.#nextId++, ...value }));
      this.#rows.push(...records);
      return {
        affected: records.length,
        records,
        ids: records.map((record) => String(record.id)),
        plan: plan("mutation", mutation.model)
      };
    }

    if (mutation.operation === "upsert") {
      throw new Error("Upsert is outside this smoke proof.");
    }

    const indexes = this.#rows
      .map((row, index) => (matches(row, mutation.where) ? index : -1))
      .filter((index) => index >= 0);

    if (mutation.operation === "delete") {
      const records = indexes.map((index) => this.#rows[index]!);
      this.#rows = this.#rows.filter((_, index) => !indexes.includes(index));
      return {
        affected: records.length,
        records,
        ids: records.map((record) => String(record.id)),
        plan: plan("mutation", mutation.model)
      };
    }

    const records = indexes.map((index) => {
      const updated = { ...this.#rows[index]!, ...mutation.changes };
      this.#rows[index] = updated;
      return updated;
    });
    return {
      affected: records.length,
      records,
      ids: records.map((record) => String(record.id)),
      plan: plan("mutation", mutation.model)
    };
  }
}

test("generated Prisma Client performs ordinary CRUD through Hibari", async () => {
  const runtime = new MemoryRuntime();
  const prisma = new PrismaClient({
    adapter: new PrismaHibari({ runtime, schema })
  });

  try {
    const alice = await prisma.user.create({
      data: { email: "alice@example.test", name: "Alice" }
    });
    assert.deepEqual(alice, {
      id: 1,
      email: "alice@example.test",
      name: "Alice"
    });

    const unique = await prisma.user.findUnique({
      where: { email: "alice@example.test" }
    });
    assert.equal(unique?.id, 1);

    const list = await prisma.user.findMany({
      where: { email: "alice@example.test" },
      orderBy: { id: "asc" }
    });
    assert.equal(list.length, 1);
    assert.equal(list[0]?.name, "Alice");

    const updated = await prisma.user.update({
      where: { id: 1 },
      data: { name: "Alicia" }
    });
    assert.equal(updated.name, "Alicia");

    const deleted = await prisma.user.delete({ where: { id: 1 } });
    assert.equal(deleted.id, 1);
    assert.equal(await prisma.user.findUnique({ where: { id: 1 } }), null);

    assert.ok(runtime.operations.some((operation) => operation.kind === "query"));
    assert.ok(runtime.operations.some((operation) => operation.kind === "mutation"));
    assert.ok(runtime.operations.every((operation) => operation.model === "User"));
  } finally {
    await prisma.$disconnect();
  }
});
