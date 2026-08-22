import { appendFileSync, writeFileSync } from "node:fs";
import { KintoneBackend } from "@hibari/kintone";
import { createRuntimeHttpServer } from "@hibari/runtime-http";

const endpointFile = process.argv[2];
const requestLog = process.argv[3];
if (!endpointFile || !requestLog) {
  throw new Error("usage: wordpress-kintone-user-runtime.mjs ENDPOINT_FILE REQUEST_LOG");
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

function stringEquality(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s*=\\s*\"((?:\\\\.|[^\"])*)\"`, "i").exec(query);
  return match ? unescapeKintoneString(match[1]) : undefined;
}

function stringInequality(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s*!=\\s*\"((?:\\\\.|[^\"])*)\"`, "i").exec(query);
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

function redactRequest(request) {
  const clone = structuredClone(request);
  const redactRecord = (record) => {
    if (record?.User_pass) {
      record.User_pass = { value: "[REDACTED]" };
    }
  };
  redactRecord(clone.body?.record);
  for (const item of clone.body?.records ?? []) {
    redactRecord(item?.record);
  }
  return clone;
}

class FakeKintoneUserTransport {
  #nextOptionId = 4;
  #nextUserId = 1;
  #nextUserMetaId = 1;
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
    ],
    [
      "2",
      {
        id: "2",
        revision: 1,
        fields: {
          Option_name: "blog_charset",
          Option_value: "UTF-8",
          Autoload: "on"
        }
      }
    ],
    [
      "3",
      {
        id: "3",
        revision: 1,
        fields: {
          Option_name: "wp_user_roles",
          Option_value: "a:0:{}",
          Autoload: "on"
        }
      }
    ]
  ]);

  #userRecords = new Map();
  #userMetaRecords = new Map();

  #optionMatches(query, record) {
    const name = stringEquality(query, "Option_name");
    if (name !== undefined && record.fields.Option_name !== name) return false;
    return true;
  }

  #userMatches(query, record) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;

    const login = stringEquality(query, "User_login");
    if (login !== undefined && record.fields.User_login !== login) return false;
    const loginNe = stringInequality(query, "User_login");
    if (loginNe !== undefined && record.fields.User_login === loginNe) return false;

    const email = stringEquality(query, "User_email");
    if (email !== undefined && record.fields.User_email !== email) return false;
    const nicename = stringEquality(query, "User_nicename");
    if (nicename !== undefined && record.fields.User_nicename !== nicename) return false;
    return true;
  }

  #userMetaMatches(query, record) {
    const id = numericEquality(query, "$id");
    if (id !== undefined && record.id !== id) return false;
    const ids = numericIn(query, "$id");
    if (ids !== undefined && !ids.includes(record.id)) return false;

    const owner = numericEquality(query, "User_id");
    if (owner !== undefined && String(record.fields.User_id) !== owner) return false;
    const owners = numericIn(query, "User_id");
    if (owners !== undefined && !owners.includes(String(record.fields.User_id))) return false;

    const key = stringEquality(query, "Meta_key");
    if (key !== undefined && record.fields.Meta_key !== key) return false;
    const value = stringEquality(query, "Meta_value");
    if (value !== undefined && record.fields.Meta_value !== value) return false;
    return true;
  }

  #store(app) {
    if (Number(app) === 84) return this.#optionRecords;
    if (Number(app) === 90) return this.#userRecords;
    if (Number(app) === 91) return this.#userMetaRecords;
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  #newId(app) {
    if (Number(app) === 84) return String(this.#nextOptionId++);
    if (Number(app) === 90) return String(this.#nextUserId++);
    if (Number(app) === 91) return String(this.#nextUserMetaId++);
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  #matchingRecords(app, query) {
    let records;
    if (Number(app) === 84) {
      records = [...this.#optionRecords.values()].filter((record) => this.#optionMatches(query, record));
    } else if (Number(app) === 90) {
      records = [...this.#userRecords.values()].filter((record) => this.#userMatches(query, record));
    } else if (Number(app) === 91) {
      records = [...this.#userMetaRecords.values()].filter((record) => this.#userMetaMatches(query, record));
    } else {
      throw new Error(`Unexpected fake Kintone app ${app}`);
    }
    return records.sort((left, right) => Number(left.id) - Number(right.id));
  }

  async request(request) {
    appendFileSync(requestLog, `${JSON.stringify(redactRequest(request))}\n`);
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

const backend = new KintoneBackend(new FakeKintoneUserTransport(), [
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
    model: "User",
    app: 90,
    fieldCodes: {
      id: "$id",
      login: "User_login",
      passwordHash: "User_pass",
      nicename: "User_nicename",
      email: "User_email",
      url: "User_url",
      registeredAt: "User_registered",
      activationKey: "User_activation_key",
      status: "User_status",
      displayName: "Display_name"
    },
    uniqueFields: ["id", "login", "email"]
  },
  {
    model: "UserMeta",
    app: 91,
    fieldCodes: {
      id: "$id",
      ownerId: "User_id",
      key: "Meta_key",
      value: "Meta_value"
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
