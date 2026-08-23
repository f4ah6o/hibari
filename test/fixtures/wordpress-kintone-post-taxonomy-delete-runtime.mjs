import { appendFileSync, writeFileSync } from "node:fs";
import { KintoneBackend } from "@hibari/kintone";
import { createRuntimeHttpServer } from "@hibari/runtime-http";

const endpointFile = process.argv[2];
const requestLog = process.argv[3];
if (!endpointFile || !requestLog) {
  throw new Error(
    "usage: wordpress-kintone-post-taxonomy-delete-runtime.mjs ENDPOINT_FILE REQUEST_LOG"
  );
}

function unescapeKintoneString(value) {
  return value.replace(/\\\"/g, '"').replace(/\\\\/g, "\\");
}

function wrappedRecord(record, requestedFields) {
  const source = { $id: record.id, $revision: String(record.revision), ...record.fields };
  const fields = requestedFields ?? Object.keys(source);
  return Object.fromEntries(
    fields.filter((field) => field in source).map((field) => [field, { value: source[field] }])
  );
}

function applyWrapped(record, wrapped) {
  for (const [field, item] of Object.entries(wrapped ?? {})) {
    if (field === "$id" || field === "$revision") continue;
    record.fields[field] = item?.value;
  }
  record.revision += 1;
}

function escapedField(field) {
  return field.replace(/[$]/g, "\\$");
}

function equalityValue(query, field) {
  const escaped = escapedField(field);
  const quoted = new RegExp(`${escaped}\\s*=\\s*\"((?:\\\\.|[^\"])*)\"`, "i").exec(query);
  if (quoted) return unescapeKintoneString(quoted[1]);
  const bare = new RegExp(`${escaped}\\s*=\\s*(-?\\d+(?:\\.\\d+)?)`, "i").exec(query);
  return bare ? bare[1] : undefined;
}

function inValues(query, field) {
  const escaped = escapedField(field);
  const match = new RegExp(`${escaped}\\s+in\\s*\\(([^)]*)\\)`, "i").exec(query);
  if (!match) return undefined;
  return match[1]
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean)
    .map((value) => {
      const quoted = /^\"((?:\\.|[^\"])*)\"$/.exec(value);
      return quoted ? unescapeKintoneString(quoted[1]) : value;
    });
}

function lessThanValue(query, field) {
  const escaped = escapedField(field);
  const match = new RegExp(`${escaped}\\s*<\\s*(?:\"([^\"]+)\"|(-?\\d+(?:\\.\\d+)?))`, "i").exec(
    query
  );
  return match ? String(match[1] ?? match[2]) : undefined;
}

function matchesField(query, field, value) {
  const equal = equalityValue(query, field);
  if (equal !== undefined && String(value) !== equal) return false;
  const values = inValues(query, field);
  if (values !== undefined && !values.includes(String(value))) return false;
  const lessThan = lessThanValue(query, field);
  if (lessThan !== undefined && Number(value) >= Number(lessThan)) return false;
  return true;
}

function pageWindow(query) {
  const limit = /\blimit\s+(\d+)/i.exec(query);
  const offset = /\boffset\s+(\d+)/i.exec(query);
  return {
    limit: limit ? Number(limit[1]) : undefined,
    offset: offset ? Number(offset[1]) : 0
  };
}

class FakeKintonePostTaxonomyDeleteTransport {
  #nextIds = new Map([
    [84, 4],
    [85, 1],
    [86, 1],
    [87, 1],
    [88, 1],
    [89, 1],
    [92, 1],
    [93, 1]
  ]);

  #stores = new Map([
    [
      84,
      new Map([
        [
          "1",
          {
            id: "1",
            revision: 1,
            fields: { Option_name: "db_version", Option_value: "40000", Autoload: "on" }
          }
        ],
        [
          "2",
          {
            id: "2",
            revision: 1,
            fields: { Option_name: "blog_charset", Option_value: "UTF-8", Autoload: "on" }
          }
        ],
        [
          "3",
          {
            id: "3",
            revision: 1,
            fields: { Option_name: "timezone_string", Option_value: "UTC", Autoload: "on" }
          }
        ]
      ])
    ],
    [85, new Map()],
    [86, new Map()],
    [87, new Map()],
    [88, new Map()],
    [89, new Map()],
    [92, new Map()],
    [93, new Map()]
  ]);

  #nextCursorId = 1;
  #cursors = new Map();

  #store(app) {
    const store = this.#stores.get(Number(app));
    if (!store) throw new Error(`Unexpected fake Kintone app ${app}`);
    return store;
  }

  #newId(app) {
    const key = Number(app);
    const next = this.#nextIds.get(key);
    if (next === undefined) throw new Error(`Unexpected fake Kintone app ${app}`);
    this.#nextIds.set(key, next + 1);
    return String(next);
  }

  #matches(query, record) {
    if (!matchesField(query, "$id", record.id)) return false;
    for (const [field, value] of Object.entries(record.fields)) {
      if (!matchesField(query, field, value)) return false;
    }
    return true;
  }

  #matchingRecords(app, query) {
    const records = [...this.#store(app).values()].filter((record) => this.#matches(query, record));
    records.sort((left, right) => Number(left.id) - Number(right.id));
    if (/\border\s+by\s+\$id\s+desc/i.test(query)) records.reverse();
    return records;
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
      const records = cursor.records.slice(cursor.offset, cursor.offset + cursor.size);
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

    if (request.method === "PUT" && request.path.endsWith("/records.json")) {
      const returned = [];
      for (const change of request.body?.records ?? []) {
        const record = this.#store(app).get(String(change.id));
        if (!record) throw new Error(`Unknown fake Kintone record ${change.id}`);
        applyWrapped(record, change.record);
        returned.push({ id: record.id, revision: String(record.revision) });
      }
      return { records: returned };
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

const postFieldCodes = {
  id: "$id",
  authorId: "Post_author",
  date: "Post_date",
  dateGmt: "Post_date_gmt",
  content: "Post_content",
  title: "Post_title",
  excerpt: "Post_excerpt",
  status: "Post_status",
  commentStatus: "Comment_status",
  pingStatus: "Ping_status",
  password: "Post_password",
  slug: "Post_name",
  toPing: "To_ping",
  pinged: "Pinged",
  modified: "Post_modified",
  modifiedGmt: "Post_modified_gmt",
  contentFiltered: "Post_content_filtered",
  parentId: "Post_parent",
  guid: "Guid",
  menuOrder: "Menu_order",
  type: "Post_type",
  mimeType: "Post_mime_type",
  commentCount: "Comment_count"
};

const backend = new KintoneBackend(new FakeKintonePostTaxonomyDeleteTransport(), [
  {
    model: "Option",
    app: 84,
    fieldCodes: { name: "Option_name", value: "Option_value", autoload: "Autoload" },
    uniqueFields: ["name"]
  },
  {
    model: "Post",
    app: 85,
    fieldCodes: postFieldCodes,
    uniqueFields: ["id"]
  },
  {
    model: "PostMeta",
    app: 86,
    fieldCodes: { id: "$id", ownerId: "Post_id", key: "Meta_key", value: "Meta_value" },
    uniqueFields: ["id"]
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
  },
  {
    model: "Comment",
    app: 92,
    fieldCodes: {
      id: "$id",
      postId: "Comment_post_ID",
      author: "Comment_author",
      authorEmail: "Comment_author_email",
      authorUrl: "Comment_author_url",
      authorIp: "Comment_author_IP",
      date: "Comment_date",
      dateGmt: "Comment_date_gmt",
      content: "Comment_content",
      karma: "Comment_karma",
      approved: "Comment_approved",
      agent: "Comment_agent",
      type: "Comment_type",
      parentId: "Comment_parent",
      userId: "User_id"
    },
    uniqueFields: ["id"]
  },
  {
    model: "CommentMeta",
    app: 93,
    fieldCodes: { id: "$id", ownerId: "Comment_id", key: "Meta_key", value: "Meta_value" },
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
