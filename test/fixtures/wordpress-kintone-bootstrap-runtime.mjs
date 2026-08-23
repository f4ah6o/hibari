import { appendFileSync, writeFileSync } from "node:fs";
import { KintoneBackend } from "@hibari/kintone";
import { createRuntimeHttpServer } from "@hibari/runtime-http";

const endpointFile = process.argv[2];
const requestLog = process.argv[3];
const themeSlug = process.argv[4];
if (!endpointFile || !requestLog || !themeSlug) {
  throw new Error("usage: wordpress-kintone-bootstrap-runtime.mjs ENDPOINT_FILE REQUEST_LOG THEME_SLUG");
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

function stringEquality(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s*=\\s*\"((?:\\\\.|[^\"])*)\"`, "i").exec(query);
  return match ? unescapeKintoneString(match[1]) : undefined;
}

function stringIn(query, field) {
  const escaped = field.replace(/[$]/g, "\\$");
  const match = new RegExp(`${escaped}\\s+in\\s*\\(([^)]*)\\)`, "i").exec(query);
  if (!match) return undefined;
  return [...match[1].matchAll(/\"((?:\\\\.|[^\"])*)\"/g)].map((item) =>
    unescapeKintoneString(item[1])
  );
}

function pageWindow(query) {
  const limit = /\blimit\s+(\d+)/i.exec(query);
  const offset = /\boffset\s+(\d+)/i.exec(query);
  return {
    limit: limit ? Number(limit[1]) : undefined,
    offset: offset ? Number(offset[1]) : 0
  };
}

const optionValues = [
  ["siteurl", "https://example.test", "on"],
  ["home", "https://example.test", "on"],
  ["blogname", "Hibari Bootstrap", "on"],
  ["blogdescription", "Stock WordPress on Hibari", "on"],
  ["template", themeSlug, "on"],
  ["stylesheet", themeSlug, "on"],
  ["active_plugins", "a:0:{}", "on"],
  ["blog_charset", "UTF-8", "on"],
  ["html_type", "text/html", "on"],
  ["WPLANG", "", "on"],
  ["timezone_string", "UTC", "on"],
  ["gmt_offset", "0", "on"],
  ["start_of_week", "1", "on"],
  ["date_format", "F j, Y", "on"],
  ["time_format", "g:i a", "on"],
  ["permalink_structure", "", "on"],
  ["rewrite_rules", "a:0:{}", "off"],
  ["fresh_site", "0", "off"],
  ["wp_user_roles", "a:0:{}", "on"]
];

class FakeKintoneBootstrapTransport {
  #nextCursorId = 1;
  #cursors = new Map();
  #records = new Map(
    optionValues.map(([name, value, autoload], index) => [
      String(index + 1),
      {
        id: String(index + 1),
        revision: 1,
        fields: {
          Option_name: name,
          Option_value: value,
          Autoload: autoload
        }
      }
    ])
  );

  #matchingRecords(query) {
    let records = [...this.#records.values()];
    const name = stringEquality(query, "Option_name");
    if (name !== undefined) {
      records = records.filter((record) => record.fields.Option_name === name);
    }
    const autoloadValues = stringIn(query, "Autoload");
    if (autoloadValues !== undefined) {
      records = records.filter((record) => autoloadValues.includes(String(record.fields.Autoload)));
    }
    return records.sort((left, right) => Number(left.id) - Number(right.id));
  }

  async request(request) {
    appendFileSync(requestLog, `${JSON.stringify(request)}\n`);

    if (request.method === "GET" && request.path.endsWith("/records/cursor.json")) {
      const id = String(request.body?.id ?? "");
      const cursor = this.#cursors.get(id);
      if (!cursor) throw new Error(`Unknown fake Kintone cursor ${id}`);
      const start = cursor.offset;
      const records = cursor.records.slice(start, start + cursor.size);
      cursor.offset += records.length;
      const next = cursor.offset < cursor.records.length;
      return { records, next };
    }

    if (request.method === "DELETE" && request.path.endsWith("/records/cursor.json")) {
      this.#cursors.delete(String(request.body?.id ?? ""));
      return {};
    }

    if (Number(request.body?.app) !== 84) {
      throw new Error(`Full bootstrap unexpectedly requested non-Option app ${request.body?.app}`);
    }

    if (request.method === "POST" && request.path.endsWith("/records/cursor.json")) {
      const records = this.#matchingRecords(request.body?.query ?? "").map((record) =>
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

    if (request.method === "GET" && request.path.endsWith("/records.json")) {
      let records = this.#matchingRecords(request.body?.query ?? "");
      const { limit, offset } = pageWindow(request.body?.query ?? "");
      records = records.slice(offset, limit === undefined ? undefined : offset + limit);
      return {
        records: records.map((record) => wrappedRecord(record, request.body?.fields))
      };
    }

    throw new Error(`Unexpected fake Kintone bootstrap request: ${request.method} ${request.path}`);
  }
}

const backend = new KintoneBackend(new FakeKintoneBootstrapTransport(), [
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
