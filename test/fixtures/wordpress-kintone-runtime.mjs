import { appendFileSync, writeFileSync } from "node:fs";
import { KintoneBackend } from "@hibari/kintone";
import { createRuntimeHttpServer } from "@hibari/runtime-http";

const endpointFile = process.argv[2];
const requestLog = process.argv[3];
if (!endpointFile || !requestLog) {
  throw new Error("usage: wordpress-kintone-runtime.mjs ENDPOINT_FILE REQUEST_LOG");
}

function unescapeKintoneString(value) {
  return value.replace(/\\"/g, '"').replace(/\\\\/g, "\\");
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

class FakeKintoneTransport {
  #nextOptionId = 3;
  #nextPostId = 1;
  #optionRecords = new Map([
    [
      "1",
      {
        id: "1",
        revision: 1,
        fields: {
          Option_name: "siteurl",
          Option_value: "https://kintone-backed.example.test/",
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
          Option_name: "hibari_existing",
          Option_value: "before",
          Autoload: "off"
        }
      }
    ]
  ]);
  #postRecords = new Map();

  #nameFromQuery(query) {
    const match = /Option_name\s*=\s*"((?:\\.|[^"])*)"/.exec(query);
    return match ? unescapeKintoneString(match[1]) : undefined;
  }

  #idFromQuery(query) {
    const match = /\$id\s*=\s*(?:"(\d+)"|(\d+))/.exec(query);
    return match ? String(match[1] ?? match[2]) : undefined;
  }

  #findOptionByName(name) {
    return [...this.#optionRecords.values()].find(
      (record) => record.fields.Option_name === name
    );
  }

  #store(app) {
    if (Number(app) === 84) return this.#optionRecords;
    if (Number(app) === 85) return this.#postRecords;
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  #newId(app) {
    if (Number(app) === 84) return String(this.#nextOptionId++);
    if (Number(app) === 85) return String(this.#nextPostId++);
    throw new Error(`Unexpected fake Kintone app ${app}`);
  }

  async request(request) {
    appendFileSync(requestLog, `${JSON.stringify(request)}\n`);
    const app = request.body?.app;

    if (request.method === "GET" && request.path.endsWith("/records.json")) {
      const query = request.body?.query ?? "";
      let record;
      if (Number(app) === 84) {
        const name = this.#nameFromQuery(query);
        record = name === undefined ? undefined : this.#findOptionByName(name);
      } else if (Number(app) === 85) {
        const id = this.#idFromQuery(query);
        record = id === undefined ? undefined : this.#postRecords.get(id);
      } else {
        throw new Error(`Unexpected fake Kintone app ${app}`);
      }
      return {
        records: record ? [wrappedRecord(record, request.body?.fields)] : []
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

const backend = new KintoneBackend(new FakeKintoneTransport(), [
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
    model: "Post",
    app: 85,
    fieldCodes: postFieldCodes,
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
