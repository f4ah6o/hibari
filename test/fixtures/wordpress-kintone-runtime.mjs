import { appendFileSync, writeFileSync } from "node:fs";
import { KintoneBackend } from "@hibari/kintone";
import { createRuntimeHttpServer } from "@hibari/runtime-http";

const endpointFile = process.argv[2];
const requestLog = process.argv[3];
if (!endpointFile || !requestLog) {
  throw new Error("usage: wordpress-kintone-runtime.mjs ENDPOINT_FILE REQUEST_LOG");
}

class FakeKintoneTransport {
  async request(request) {
    appendFileSync(requestLog, `${JSON.stringify(request)}\n`);

    if (request.method === "GET" && request.path.endsWith("/records.json")) {
      const query = request.body?.query ?? "";
      if (!query.includes('Option_name = "siteurl"')) {
        return { records: [] };
      }
      return {
        records: [
          {
            Option_value: {
              type: "MULTI_LINE_TEXT",
              value: "https://kintone-backed.example.test/"
            }
          }
        ]
      };
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
      value: "Option_value"
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
