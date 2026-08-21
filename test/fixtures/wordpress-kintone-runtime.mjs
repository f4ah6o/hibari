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

class FakeKintoneTransport {
  #nextId = 3;
  #records = new Map([
    [
      "1",
      {
        id: "1",
        revision: 1,
        Option_name: "siteurl",
        Option_value: "https://kintone-backed.example.test/",
        Autoload: "on"
      }
    ],
    [
      "2",
      {
        id: "2",
        revision: 1,
        Option_name: "hibari_existing",
        Option_value: "before",
        Autoload: "off"
      }
    ]
  ]);

  #nameFromQuery(query) {
    const match = /Option_name\s*=\s*"((?:\\.|[^"])*)"/.exec(query);
    return match ? unescapeKintoneString(match[1]) : undefined;
  }

  #findByName(name) {
    return [...this.#records.values()].find((record) => record.Option_name === name);
  }

  #wrapped(record, requestedFields) {
    const source = {
      $id: record.id,
      $revision: String(record.revision),
      Option_name: record.Option_name,
      Option_value: record.Option_value,
      Autoload: record.Autoload
    };
    const fields = requestedFields ?? Object.keys(source);
    return Object.fromEntries(
      fields
        .filter((field) => field in source)
        .map((field) => [field, { value: source[field] }])
    );
  }

  #applyWrapped(record, wrapped) {
    for (const [field, item] of Object.entries(wrapped ?? {})) {
      if (field === "$id" || field === "$revision") continue;
      record[field] = item?.value;
    }
    record.revision += 1;
  }

  async request(request) {
    appendFileSync(requestLog, `${JSON.stringify(request)}\n`);

    if (request.method === "GET" && request.path.endsWith("/records.json")) {
      const query = request.body?.query ?? "";
      const name = this.#nameFromQuery(query);
      const record = name === undefined ? undefined : this.#findByName(name);
      return {
        records: record ? [this.#wrapped(record, request.body?.fields)] : []
      };
    }

    if (request.method === "POST" && request.path.endsWith("/record.json")) {
      const id = String(this.#nextId++);
      const record = {
        id,
        revision: 1,
        Option_name: "",
        Option_value: "",
        Autoload: "off"
      };
      for (const [field, item] of Object.entries(request.body?.record ?? {})) {
        record[field] = item?.value;
      }
      this.#records.set(id, record);
      return { id, revision: "1" };
    }

    if (request.method === "PUT" && request.path.endsWith("/record.json")) {
      const record = this.#records.get(String(request.body?.id));
      if (!record) throw new Error(`Unknown fake Kintone record ${request.body?.id}`);
      this.#applyWrapped(record, request.body?.record);
      return { revision: String(record.revision) };
    }

    if (request.method === "PUT" && request.path.endsWith("/records.json")) {
      for (const change of request.body?.records ?? []) {
        const record = this.#records.get(String(change.id));
        if (!record) throw new Error(`Unknown fake Kintone record ${change.id}`);
        this.#applyWrapped(record, change.record);
      }
      return {};
    }

    if (request.method === "DELETE" && request.path.endsWith("/records.json")) {
      for (const id of request.body?.ids ?? []) {
        this.#records.delete(String(id));
      }
      return {};
    }

    throw new Error(`Unexpected fake Kintone request: ${request.method} ${request.path}`);
  }
}

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
