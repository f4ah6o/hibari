import { appendFileSync, writeFileSync } from "node:fs";
import { KintoneBackend } from "@hibari/kintone";
import { createRuntimeHttpServer } from "@hibari/runtime-http";

const endpointFile = process.argv[2];
const requestLog = process.argv[3];
if (!endpointFile || !requestLog) {
  throw new Error("usage: wordpress-kintone-term-runtime.mjs ENDPOINT_FILE REQUEST_LOG");
}

function unescapeKintoneString(value) {
  return value.replace(/\\\"/g, '"').replace(/\\\\/g, "\\");
}

function wrappedRecord(record, requestedFields) {
  const source = {
    $id: record.id,
    $revision: String(record.revision),
    ...record.fields
  };
  const fields = requestedFields ?? Object.keys(source);
  return Object.fromEntries(
    fields
      .filter((field) => field in source)
      .map((field) => [field, { value: source[field] }])
  );
}

function applyWrapped(record, wrapped) {
  for (const [field, item] of Object.entries(wrapped ?? {})) {
    if (field === "$id" || field === "$revision") continue;
    record.fields[field] = item?.value;
  }
  record.revision += 1;
}

function numericEquality(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s*=\\s*(?:\"(\\d+)\"|(\\d+))`, "i").exec(query);
  return match ? String(match[1] ?? match[2]) : undefined;
}

function numericIn(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s+in\\s*\\(([^)]*)\\)`, "i").exec(query);
  if (!match) return undefined;
  return match[1]
    .split(",")
    .map((value) => value.trim().replace(/^\"|\"$/g, ""))
    .filter((value) => /^\d+$/.test(value));
}

function numericLessThan(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s*<\\s*(?:\"(\\d+)\"|(\\d+))`, "i").exec(query);
  return match ? Number(match[1] ?? match[2]) : undefined;
}

function stringEquality(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s*=\\s*\"((?:\\\\.|[^\"])*)\"`, "i").exec(query);
  return match ? unescapeKintoneString(match[1]) : undefined;
}

function pageWindow(query) {
  const limit = /\blimit\s+(\d+)/i.exec(query);
  const offset = /\boffset\s+(\d+)/i.exec(query);
  return {
    limit: limit ? Number(limit[1]) : undefined,
    offset: offset ? Number(offset[1]) : 0
  };
}

class FakeKintoneTermTransport {
  #nextOptionId = 2;
  #nextTermId = 1;
  #nextTermTaxonomyId = 1;
  #nextRelationshipId = 1;
  #nextCursorId = 1;
  #cursors = new Map();

  #optionRecords = new Map([
    [
      "1",
      {
        id: "1",
        revision: 1,
        fields: {
          Option_name: "db_version",
          Option_value: "40000",
          Autoload: "on"
        }
      }
    ]
  ]);

  #termRecords = new Map();
  #termTaxonomyRecords = new Map();
  #relationshipRecords = new Map();

  #optionMatches(query, record) {
    const name = stringEquality(query, "Option_name");
    if (name !== undefined && record.fields.Option_name !== name) return false;
    return true;
  }

  #termMatches(query, record) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;
    const ids = numericIn(query, "$id");
    if (ids !== undefined && !ids.includes(record.id)) return false;
    const lessThanId = numericLessThan(query, "$id");
    if (lessThanId !== undefined && Number(record.id) >= lessThanId) return false;

    const name = stringEquality(query, "Term_name");
    if (name !== undefined && record.fields.Term_name !== name) return false;
    const slug = stringEquality(query, "Slug");
    if (slug !== undefined && record.fields.Slug !== slug) return false;
    return true;
  }

  #termTaxonomyMatches(query, record) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;
    const ids = numericIn(query, "$id");
    if (ids !== undefined && !ids.includes(record.id)) return false;

    const termId = numericEquality(query, "Term_id");
    if (termId !== undefined && String(record.fields.Term_id) !== termId) return false;
    const termIds = numericIn(query, "Term_id");
    if (termIds !== undefined && !termIds.includes(String(record.fields.Term_id))) return false;

    const taxonomy = stringEquality(query, "Taxonomy");
    if (taxonomy !== undefined && record.fields.Taxonomy !== taxonomy) return false;
    const parent = numericEquality(query, "Parent");
    if (parent !== undefined && String(record.fields.Parent) !== parent) return false;
    return true;
  }

  #relationshipMatches(query, record) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;
    const ids = numericIn(query, "$id");
    if (ids !== undefined && !ids.includes(record.id)) return false;

    const objectId = numericEquality(query, "Object_id");
    if (objectId !== undefined && String(record.fields.Object_id) !== objectId) return false;
    const objectIds = numericIn(query, "Object_id");
    if (objectIds !== undefined && !objectIds.includes(String(record.fields.Object_id))) return false;

    const termTaxonomyId = numericEquality(query, "Term_taxonomy_id");
    if (termTaxonomyId !== undefined && String(record.fields.Term_taxonomy_id) !== termTaxonomyId) return false;
    const termTaxonomyIds = numericIn(query, "Term_taxonomy_id");
    if (
      termTaxonomyIds !== undefined
      && !termTaxonomyIds.includes(String(record.fields.Term_taxonomy_id))
    ) return false;
    return true;
  }

  #store(app) {
    if (Number(app) === 84) return this.#optionRecords;
    if (Number(app) === 87) return this.#termTaxonomyRecords;
    if (Number(app) === 88) return this.#relationshipRecords;
    if (Number(app) === 89) return this.#termRecords;
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  #newId(app) {
    if (Number(app) === 84) return String(this.#nextOptionId++);
    if (Number(app) === 87) return String(this.#nextTermTaxonomyId++);
    if (Number(app) === 88) return String(this.#nextRelationshipId++);
    if (Number(app) === 89) return String(this.#nextTermId++);
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  #matchingRecords(app, query) {
    let records;
    if (Number(app) === 84) {
      records = [...this.#optionRecords.values()].filter((record) => this.#optionMatches(query, record));
    } else if (Number(app) === 87) {
      records = [...this.#termTaxonomyRecords.values()].filter((record) =>
        this.#termTaxonomyMatches(query, record)
      );
    } else if (Number(app) === 88) {
      records = [...this.#relationshipRecords.values()].filter((record) =>
        this.#relationshipMatches(query, record)
      );
    } else if (Number(app) === 89) {
      records = [...this.#termRecords.values()].filter((record) => this.#termMatches(query, record));
    } else {
      throw new Error(`Unexpected fake Kintone app ${app}`);
    }

    return records.sort((left, right) => Number(left.id) - Number(right.id));
  }

  async request(request) {
    appendFileSync(requestLog, `${JSON.stringify(request)}\n`);
    const app = request.body?.app;

    if (request.method === "POST" && request.path.endsWith("/records/cursor.json")) {
      const query = request.body?.query ?? "";
      const records = this.#matchingRecords(app, query).map((record) =>
        wrappedRecord(record, request.body?.fields)
      );
      const id = `cursor-${this.#nextCursorId++}`;
      this.#cursors.set(id, {
        records,
        size: Number(request.body?.size ?? 500),
        offset: 0
      });
      return { id, totalCount: String(records.length) };
    }

    if (request.method === "GET" && request.path.endsWith("/records/cursor.json")) {
      const id = String(request.body?.id ?? "");
      const cursor = this.#cursors.get(id);
      if (!cursor) throw new Error(`Unknown fake Kintone cursor ${id}`);
      const start = cursor.offset;
      const records = cursor.records.slice(start, start + cursor.size);
      cursor.offset += records.length;
      const next = cursor.offset < cursor.records.length;
      if (!next) this.#cursors.delete(id);
      return { records, next };
    }

    if (request.method === "DELETE" && request.path.endsWith("/records/cursor.json")) {
      this.#cursors.delete(String(request.body?.id ?? ""));
      return {};
    }

    if (request.method === "GET" && request.path.endsWith("/records.json")) {
      const query = request.body?.query ?? "";
      let records = this.#matchingRecords(app, query);
      const { limit, offset } = pageWindow(query);
      records = records.slice(offset, limit === undefined ? undefined : offset + limit);
      return {
        records: records.map((record) => wrappedRecord(record, request.body?.fields))
      };
    }

    if (request.method === "POST" && request.path.endsWith("/record.json")) {
      const id = this.#newId(app);
      const record = { id, revision: 1, fields: {} };
      for (const [field, item] of Object.entries(request.body?.record ?? {})) {
        record.fields[field] = item?.value;
      }
      this.#store(app).set(id, record);
      return { id, revision: "1" };
    }

    if (request.method === "PUT" && request.path.endsWith("/record.json")) {
      const record = this.#store(app).get(String(request.body?.id));
      if (!record) throw new Error(`Unknown fake Kintone record ${request.body?.id}`);
      applyWrapped(record, request.body?.record);
      return { revision: String(record.revision) };
    }

    if (request.method === "DELETE" && request.path.endsWith("/records.json")) {
      for (const id of request.body?.ids ?? []) {
        this.#store(app).delete(String(id));
      }
      return {};
    }

    throw new Error(`Unexpected fake Kintone request: ${request.method} ${request.path}`);
  }
}

const backend = new KintoneBackend(new FakeKintoneTermTransport(), [
  {
    model: "Option",
    app: 84,
    fieldCodes: {
      name: "Option_name",
      value: "Option_value",
      autoload: "Autoload"
    },
    uniqueFields: ["name"]
  },
  {
    model: "TermTaxonomy",
    app: 87,
    fieldCodes: {
      id: "$id",
      termId: "Term_id",
      taxonomy: "Taxonomy",
      description: "Description",
      parent: "Parent",
      count: "Count"
    },
    uniqueFields: ["id"]
  },
  {
    model: "TermRelationship",
    app: 88,
    fieldCodes: {
      id: "$id",
      leftId: "Object_id",
      rightId: "Term_taxonomy_id",
      order: "Term_order"
    },
    uniqueFields: ["id"]
  },
  {
    model: "Term",
    app: 89,
    fieldCodes: {
      id: "$id",
      name: "Term_name",
      slug: "Slug",
      group: "Term_group"
    },
    uniqueFields: ["id"]
  }
]);

const server = createRuntimeHttpServer({ runtime: backend });
server.listen(0, "127.0.0.1", () => {
  const address = server.address();
  if (!address || typeof address === "string") {
    throw new Error("Unable to determine runtime HTTP address.");
  }
  writeFileSync(endpointFile, `http://127.0.0.1:${address.port}`);
});

async function shutdown() {
  await new Promise((resolve) => server.close(resolve));
  process.exit(0);
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);
