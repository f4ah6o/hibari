import assert from "node:assert/strict";
import test from "node:test";

import {
  PrismaCompatibilityError,
  translatePrismaSql
} from "../dist/index.js";

test("translates Prisma-style SELECT into portable Query IR", () => {
  const translated = translatePrismaSql({
    sql: 'SELECT "main"."User"."id", "main"."User"."email", "main"."User"."name" FROM "main"."User" WHERE ("main"."User"."email" = ? AND 1=1) ORDER BY "main"."User"."id" ASC LIMIT ? OFFSET ?',
    args: ["alice@example.test", 1, 0]
  });

  assert.deepEqual(translated, {
    operation: {
      kind: "query",
      model: "User",
      projection: ["id", "email", "name"],
      filter: { op: "eq", field: "email", value: "alice@example.test" },
      ordering: [{ field: "id", direction: "asc" }],
      limit: 1,
      offset: 0
    },
    projection: [
      { field: "id", output: "id" },
      { field: "email", output: "email" },
      { field: "name", output: "name" }
    ]
  });
});

test("translates INSERT RETURNING into insert mutation", () => {
  const translated = translatePrismaSql({
    sql: 'INSERT INTO "main"."User" ("email","name") VALUES (?,?) RETURNING "id" AS "id", "email" AS "email", "name" AS "name"',
    args: ["alice@example.test", "Alice"]
  });

  assert.deepEqual(translated.operation, {
    kind: "mutation",
    operation: "insert",
    model: "User",
    record: { email: "alice@example.test", name: "Alice" }
  });
  assert.deepEqual(translated.returning, [
    { field: "id", output: "id" },
    { field: "email", output: "email" },
    { field: "name", output: "name" }
  ]);
});

test("translates UPDATE RETURNING into update mutation", () => {
  const translated = translatePrismaSql({
    sql: 'UPDATE "main"."User" SET "name" = ? WHERE "id" = ? RETURNING "id", "email", "name"',
    args: ["Alicia", 7]
  });

  assert.deepEqual(translated.operation, {
    kind: "mutation",
    operation: "update",
    model: "User",
    where: { op: "eq", field: "id", value: 7 },
    changes: { name: "Alicia" }
  });
});

test("translates DELETE RETURNING into delete mutation", () => {
  const translated = translatePrismaSql({
    sql: 'DELETE FROM "main"."User" WHERE "id" = ? RETURNING "id", "email", "name"',
    args: [7]
  });

  assert.deepEqual(translated.operation, {
    kind: "mutation",
    operation: "delete",
    model: "User",
    where: { op: "eq", field: "id", value: 7 }
  });
});

test("rejects JOIN before datastore execution with stable diagnostic", () => {
  assert.throws(
    () =>
      translatePrismaSql({
        sql: 'SELECT "User"."id" FROM "User" JOIN "Post" ON "Post"."userId" = "User"."id"',
        args: []
      }),
    (error) => {
      assert.ok(error instanceof PrismaCompatibilityError);
      assert.equal(error.diagnostics[0]?.code, "HIB-PRISMA-JOIN-001");
      return true;
    }
  );
});

test("rejects aggregates and transaction SQL with distinct diagnostics", () => {
  for (const [sql, code] of [
    ['SELECT COUNT(*) FROM "User"', "HIB-PRISMA-AGG-001"],
    ["BEGIN", "HIB-PRISMA-TXN-001"],
    ['CREATE TABLE "User" ("id" INTEGER)', "HIB-PRISMA-SCHEMA-001"]
  ]) {
    assert.throws(
      () => translatePrismaSql({ sql, args: [] }),
      (error) => {
        assert.ok(error instanceof PrismaCompatibilityError);
        assert.equal(error.diagnostics[0]?.code, code);
        return true;
      }
    );
  }
});
