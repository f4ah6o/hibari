import assert from "node:assert/strict";
import test from "node:test";

import { KintoneBackend, type KintoneRequest, type KintoneTransport } from "@hibari/kintone";
import { PrismaHibari } from "@hibari/prisma";
import { PrismaClient } from "../packages/prisma/test/fixtures/prisma/generated/client.ts";

type StoredUser = {
  id: number;
  revision: number;
  email: string;
  name: string | null;
};

function unescapeQueryString(value: string): string {
  return value.replace(/\\"/g, '"').replace(/\\\\/g, "\\");
}

class FakeKintoneTransport implements KintoneTransport {
  readonly requests: KintoneRequest[] = [];
  #records: StoredUser[] = [];
  #nextId = 1;

  async request<T>(request: KintoneRequest): Promise<T> {
    this.requests.push(request);

    if (request.method === "GET" && request.path.endsWith("/app/form/fields.json")) {
      return {
        revision: "1",
        properties: {
          Email: {
            type: "SINGLE_LINE_TEXT",
            code: "Email",
            required: true,
            unique: true
          },
          Name: {
            type: "SINGLE_LINE_TEXT",
            code: "Name",
            required: false
          }
        }
      } as T;
    }

    if (request.method === "POST" && request.path.endsWith("/record.json")) {
      const body = request.body as {
        record: Record<string, { value: unknown }>;
      };
      const record: StoredUser = {
        id: this.#nextId++,
        revision: 1,
        email: String(body.record.Email?.value ?? ""),
        name: body.record.Name?.value == null ? null : String(body.record.Name.value)
      };
      this.#records.push(record);
      return { id: String(record.id), revision: String(record.revision) } as T;
    }

    if (request.method === "GET" && request.path.endsWith("/records.json")) {
      const body = request.body as {
        query?: string;
        fields?: readonly string[];
      };
      const query = body.query ?? "";
      let records = [...this.#records];

      const emailMatch = query.match(/Email\s*=\s*"((?:\\.|[^"])*)"/);
      if (emailMatch?.[1] !== undefined) {
        const expected = unescapeQueryString(emailMatch[1]);
        records = records.filter((record) => record.email === expected);
      }

      const idMatch = query.match(/\$id\s*=\s*(-?\d+)/);
      if (idMatch?.[1] !== undefined) {
        const expected = Number(idMatch[1]);
        records = records.filter((record) => record.id === expected);
      }

      if (/order by \$id asc/i.test(query)) {
        records.sort((left, right) => left.id - right.id);
      } else if (/order by \$id desc/i.test(query)) {
        records.sort((left, right) => right.id - left.id);
      }

      const offset = Number(query.match(/offset\s+(\d+)/i)?.[1] ?? 0);
      const limit = Number(query.match(/limit\s+(\d+)/i)?.[1] ?? 500);
      records = records.slice(offset, offset + limit);

      return {
        records: records.map((record) => this.#encode(record, body.fields))
      } as T;
    }

    if (request.method === "PUT" && request.path.endsWith("/records.json")) {
      const body = request.body as {
        records: readonly {
          id: string | number;
          record: Record<string, { value: unknown }>;
        }[];
      };
      const returned: { id: string; revision: string }[] = [];
      for (const update of body.records) {
        const record = this.#records.find((candidate) => candidate.id === Number(update.id));
        if (record === undefined) {
          continue;
        }
        if (update.record.Email !== undefined) {
          record.email = String(update.record.Email.value);
        }
        if (update.record.Name !== undefined) {
          record.name = update.record.Name.value == null ? null : String(update.record.Name.value);
        }
        record.revision += 1;
        returned.push({ id: String(record.id), revision: String(record.revision) });
      }
      return { records: returned } as T;
    }

    if (request.method === "DELETE" && request.path.endsWith("/records.json")) {
      const body = request.body as { ids: readonly (string | number)[] };
      const ids = new Set(body.ids.map(Number));
      this.#records = this.#records.filter((record) => !ids.has(record.id));
      return {} as T;
    }

    throw new Error(`Unexpected fake kintone request: ${request.method} ${request.path}`);
  }

  #encode(record: StoredUser, fields?: readonly string[]) {
    const complete = {
      $id: { type: "__ID__", value: String(record.id) },
      $revision: { type: "__REVISION__", value: String(record.revision) },
      Email: { type: "SINGLE_LINE_TEXT", value: record.email },
      Name: { type: "SINGLE_LINE_TEXT", value: record.name }
    };
    if (fields === undefined) {
      return complete;
    }
    return Object.fromEntries(fields.flatMap((field) => (field in complete ? [[field, complete[field as keyof typeof complete]]] : [])));
  }
}

async function runOrdinaryApplicationCrud(prisma: PrismaClient) {
  const created = await prisma.user.create({
    data: { email: "alice@example.test", name: "Alice" }
  });
  assert.deepEqual(created, {
    id: 1,
    email: "alice@example.test",
    name: "Alice"
  });

  const found = await prisma.user.findUnique({ where: { email: "alice@example.test" } });
  assert.equal(found?.id, 1);

  const list = await prisma.user.findMany({
    where: { email: "alice@example.test" },
    orderBy: { id: "asc" },
    take: 10
  });
  assert.equal(list.length, 1);

  const updated = await prisma.user.update({
    where: { id: 1 },
    data: { name: "Alicia" }
  });
  assert.equal(updated.name, "Alicia");

  const deleted = await prisma.user.delete({ where: { id: 1 } });
  assert.equal(deleted.id, 1);
  assert.equal(await prisma.user.findUnique({ where: { id: 1 } }), null);
}

test("generated Prisma Client composes with the kintone backend without kintone concepts in application CRUD", async () => {
  const transport = new FakeKintoneTransport();
  const backend = new KintoneBackend(transport, [
    {
      model: "User",
      app: 42,
      fieldCodes: {
        id: "$id",
        revision: "$revision",
        email: "Email",
        name: "Name"
      },
      uniqueFields: ["email"]
    }
  ]);
  const introspection = await backend.introspect("User");
  const prisma = new PrismaClient({
    adapter: new PrismaHibari({
      runtime: backend,
      schema: { models: [introspection.schema] }
    })
  });

  try {
    await runOrdinaryApplicationCrud(prisma);
  } finally {
    await prisma.$disconnect();
  }

  assert.ok(transport.requests.some((request) => request.path.endsWith("/record.json")));
  assert.ok(transport.requests.some((request) => request.path.endsWith("/records.json")));
});
