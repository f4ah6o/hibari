import type { KintoneRequest, KintoneTransport } from "./types.js";

export interface FetchKintoneTransportOptions {
  readonly baseUrl: string;
  readonly apiToken?: string;
  readonly headers?: Readonly<Record<string, string>>;
  readonly fetch?: typeof globalThis.fetch;
}

export class KintoneHttpError extends Error {
  readonly status: number;
  readonly response: unknown;

  constructor(status: number, response: unknown) {
    super(`Kintone REST API request failed with HTTP ${status}.`);
    this.name = "KintoneHttpError";
    this.status = status;
    this.response = response;
  }
}

export class FetchKintoneTransport implements KintoneTransport {
  readonly #baseUrl: string;
  readonly #headers: Readonly<Record<string, string>>;
  readonly #fetch: typeof globalThis.fetch;

  constructor(options: FetchKintoneTransportOptions) {
    this.#baseUrl = options.baseUrl.replace(/\/$/, "");
    this.#headers = {
      ...(options.apiToken === undefined ? {} : { "X-Cybozu-API-Token": options.apiToken }),
      ...(options.headers ?? {})
    };
    this.#fetch = options.fetch ?? globalThis.fetch;
  }

  async request<T>(request: KintoneRequest): Promise<T> {
    const response = await this.#fetch(`${this.#baseUrl}${request.path}`, {
      method: request.method,
      headers: {
        Accept: "application/json",
        ...(request.body === undefined ? {} : { "Content-Type": "application/json" }),
        ...this.#headers
      },
      ...(request.body === undefined ? {} : { body: JSON.stringify(request.body) })
    });

    const text = await response.text();
    const body: unknown = text.length === 0 ? {} : JSON.parse(text);
    if (!response.ok) {
      throw new KintoneHttpError(response.status, body);
    }
    return body as T;
  }
}
