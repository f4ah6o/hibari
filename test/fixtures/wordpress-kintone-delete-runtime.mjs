import { appendFileSync, writeFileSync } from "node:fs";
import { KintoneBackend } from "@hibari/kintone";
import { createRuntimeHttpServer } from "@hibari/runtime-http";

const endpointFile = process.argv[2];
const requestLog = process.argv[3];
if (!endpointFile || !requestLog) {
  throw new Error("usage: wordpress-kintone-delete-runtime.mjs ENDPOINT_FILE REQUEST_LOG");
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

function stringEquality(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s*=\\s*\"((?:\\\\.|[^\"])*)\"`, "i").exec(query);
  return match ? unescapeKintoneString(match[1]) : undefined;
}

function pageWindow(query) {
  const limit = /\blimit\s+(\d+)/i.exec(query);
  const offset = /\boffset\s+(\d+)/i.exec(query);
  return { limit: limit ? Number(limit[1]) : undefined, offset: offset ? Number(offset[1]) : 0 };
}

class FakeKintoneDeleteTransport {
  #nextOptionId = 4;
  #nextPostId = 1;
  #nextPostMetaId = 1;
  #nextCommentId = 1;
  #nextCommentMetaId = 1;
  #nextCursorId = 1;
  #cursors = new Map();

  #optionRecords = new Map([
    ["1", { id: "1", revision: 1, fields: { Option_name: "db_version", Option_value: "40000", Autoload: "on" } }],
    ["2", { id: "2", revision: 1, fields: { Option_name: "blog_charset", Option_value: "UTF-8", Autoload: "on" } }],
    ["3", { id: "3", revision: 1, fields: { Option_name: "timezone_string", Option_value: "UTC", Autoload: "on" } }]
  ]);
  #postRecords = new Map();
  #postMetaRecords = new Map();
  #commentRecords = new Map();
  #commentMetaRecords = new Map();

  #optionMatches(query, record) {
    const name = stringEquality(query, "Option_name");
    return name === undefined || record.fields.Option_name === name;
  }

  #postMatches(query, record) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;
    const parent = numericEquality(query, "Post_parent");
    if (parent !== undefined && String(record.fields.Post_parent ?? 0) !== parent) return false;
    const type = stringEquality(query, "Post_type");
    if (type !== undefined && record.fields.Post_type !== type) return false;
    return true;
  }

  #metadataMatches(query, record, ownerField) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;
    const ids = numericIn(query, "$id");
    if (ids !== undefined && !ids.includes(record.id)) return false;
    const owner = numericEquality(query, ownerField);
    if (owner !== undefined && String(record.fields[ownerField]) !== owner) return false;
    const owners = numericIn(query, ownerField);
    if (owners !== undefined && !owners.includes(String(record.fields[ownerField]))) return false;
    const key = stringEquality(query, "Meta_key");
    if (key !== undefined && record.fields.Meta_key !== key) return false;
    const value = stringEquality(query, "Meta_value");
    if (value !== undefined && record.fields.Meta_value !== value) return false;
    return true;
  }

  #commentMatches(query, record) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;
    const postId = numericEquality(query, "Comment_post_ID");
    if (postId !== undefined && String(record.fields.Comment_post_ID) !== postId) return false;
    const parentId = numericEquality(query, "Comment_parent");
    if (parentId !== undefined && String(record.fields.Comment_parent ?? 0) !== parentId) return false;
    return true;
  }

  #store(app) {
    if (Number(app) === 84) return this.#optionRecords;
    if (Number(app) === 85) return this.#postRecords;
    if (Number(app) === 86) return this.#postMetaRecords;
    if (Number(app) === 92) return this.#commentRecords;
    if (Number(app) === 93) return this.#commentMetaRecords;
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  #newId(app) {
    if (Number(app) === 84) return String(this.#nextOptionId++);
    if (Number(app) === 85) return String(this.#nextPostId++);
    if (Number(app) === 86) return String(this.#nextPostMetaId++);
    if (Number(app) === 92) return String(this.#nextCommentId++);
    if (Number(app) === 93) return String(this.#nextCommentMetaId++);
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  #matchingRecords(app, query) {
    let records;
    if (Number(app) === 84) {
      records = [...this.#optionRecords.values()].filter((record) => this.#optionMatches(query, record));
    } else if (Number(app) === 85) {
      records = [...this.#postRecords.values()].filter((record) => this.#postMatches(query, record));
    } else if (Number(app) === 86) {
      records = [...this.#postMetaRecords.values()].filter((record) => this.#metadataMatches(query, record, "Post_id"));
    } else if (Number(app) === 92) {
      records = [...this.#commentRecords.values()].filter((record) => this.#commentMatches(query, record));
    } else if (Number(app) === 93) {
      records = [...this.#commentMetaRecords.values()].filter((record) => this.#metadataMatches(query, record, "Comment_id"));
    } else {
      throw new Error(`Unexpected fake Kintone app ${app}`);
    }

    records.sort((left, right) => Number(left.id) - Number(right.id));
    if (/\border\s+by\s+\$id\s+desc/i.test(query)) records.reverse();
    return records;
  }

  async request(request) {
    appendFileSync(requestLog, `${JSON.stringify(request)}\n`);
    const app = request.body?.app;

    if (request.method === "POST" && request.path.endsWith("/records/cursor.json")) {
      const query = request.body?.query ?? "";
      const records = this.#matchingRecords(app, query).map((record) => wrappedRecord(record, request.body?.fields));
      const id = `cursor-${this.#nextCursorId++}`;
      this.#cursors.set(id, { records, size: Number(request.body?.size ?? 500), offset: 0 });
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
      return { records: records.map((record) => wrappedRecord(record, request.body?.fields)) };
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
      for (const id of request.body?.ids ?? []) this.#store(app).delete(String(id));
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

const backend = new KintoneBackend(new FakeKintoneDeleteTransport(), [
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
  if (!address || typeof address === "string") throw new Error("Unable to determine runtime HTTP address.");
  writeFileSync(endpointFile, `http://127.0.0.1:${address.port}`);
});

async function shutdown() {
  await new Promise((resolve) => server.close(resolve));
  process.exit(0);
}
process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);
