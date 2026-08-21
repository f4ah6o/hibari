import { createServer, type IncomingMessage, type Server, type ServerResponse } from "node:http";
import type { DatastoreRuntime, Diagnostic, MutationIR, QueryIR } from "@hibari/core";

export interface RuntimeHttpServerOptions {
  readonly runtime: DatastoreRuntime;
  readonly maxBodyBytes?: number;
}

export interface RuntimeHttpErrorBody {
  readonly error: {
    readonly message: string;
    readonly diagnostics?: readonly Diagnostic[];
  };
}

function json(response: ServerResponse, statusCode: number, body: unknown): void {
  response.statusCode = statusCode;
  response.setHeader("content-type", "application/json; charset=utf-8");
  response.end(JSON.stringify(body));
}

async function readJson(request: IncomingMessage, maximum: number): Promise<unknown> {
  const chunks: Buffer[] = [];
  let size = 0;

  for await (const chunk of request) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    size += buffer.length;
    if (size > maximum) {
      throw Object.assign(new Error(`Request body exceeds ${maximum} bytes.`), {
        statusCode: 413
      });
    }
    chunks.push(buffer);
  }

  if (chunks.length === 0) {
    throw Object.assign(new Error("Request body must contain JSON."), { statusCode: 400 });
  }

  try {
    return JSON.parse(Buffer.concat(chunks).toString("utf8"));
  } catch {
    throw Object.assign(new Error("Request body is not valid JSON."), { statusCode: 400 });
  }
}

function diagnostics(error: unknown): readonly Diagnostic[] | undefined {
  if (
    typeof error === "object" &&
    error !== null &&
    "diagnostics" in error &&
    Array.isArray((error as { readonly diagnostics?: unknown }).diagnostics)
  ) {
    return (error as { readonly diagnostics: readonly Diagnostic[] }).diagnostics;
  }
  return undefined;
}

function message(error: unknown): string {
  return error instanceof Error ? error.message : "Hibari runtime request failed.";
}

function statusCode(error: unknown): number {
  if (
    typeof error === "object" &&
    error !== null &&
    "statusCode" in error &&
    typeof (error as { readonly statusCode?: unknown }).statusCode === "number"
  ) {
    return (error as { readonly statusCode: number }).statusCode;
  }
  return diagnostics(error) === undefined ? 500 : 422;
}

function isQuery(value: unknown): value is QueryIR {
  return typeof value === "object" && value !== null && (value as { kind?: unknown }).kind === "query";
}

function isMutation(value: unknown): value is MutationIR {
  return typeof value === "object" && value !== null && (value as { kind?: unknown }).kind === "mutation";
}

/**
 * Creates the proof-oriented HTTP projection of DatastoreRuntime.
 *
 * The server intentionally has no authentication/TLS/public-deployment policy.
 * Consumers should bind it to loopback or place it behind a deployment-specific
 * trusted boundary. The protocol itself contains no SQL, WordPress, or backend
 * concepts.
 */
export function createRuntimeHttpServer(options: RuntimeHttpServerOptions): Server {
  const maximum = options.maxBodyBytes ?? 1024 * 1024;

  return createServer(async (request, response) => {
    if (request.method !== "POST") {
      json(response, 405, { error: { message: "Only POST is supported." } });
      return;
    }

    try {
      const body = await readJson(request, maximum);

      if (request.url === "/v1/query") {
        if (!isQuery(body)) {
          json(response, 400, { error: { message: "Expected a Hibari QueryIR body." } });
          return;
        }
        json(response, 200, await options.runtime.query(body));
        return;
      }

      if (request.url === "/v1/mutation") {
        if (!isMutation(body)) {
          json(response, 400, { error: { message: "Expected a Hibari MutationIR body." } });
          return;
        }
        json(response, 200, await options.runtime.mutate(body));
        return;
      }

      json(response, 404, { error: { message: "Unknown Hibari runtime endpoint." } });
    } catch (error) {
      const errorDiagnostics = diagnostics(error);
      const body: RuntimeHttpErrorBody = {
        error: {
          message: message(error),
          ...(errorDiagnostics === undefined ? {} : { diagnostics: errorDiagnostics })
        }
      };
      json(response, statusCode(error), body);
    }
  });
}
