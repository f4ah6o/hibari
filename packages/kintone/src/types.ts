import type { Diagnostic, ExecutionPlan, ModelSchema, QueryIR } from "@hibari/core";

export type KintoneAppId = string | number;

export interface KintoneModelBinding {
  readonly model: string;
  readonly app: KintoneAppId;
  readonly guestSpaceId?: string | number;
  readonly fieldCodes?: Readonly<Record<string, string>>;
  readonly uniqueFields?: readonly string[];
}

export interface KintoneFieldProperty {
  readonly type: string;
  readonly code: string;
  readonly label?: string;
  readonly required?: boolean;
  readonly unique?: boolean;
  readonly enabled?: boolean;
  readonly fields?: Readonly<Record<string, KintoneFieldProperty>>;
  readonly lookup?: {
    readonly relatedApp?: { readonly app?: string; readonly code?: string } | null;
    readonly relatedKeyField?: string;
  } | null;
  readonly referenceTable?: {
    readonly relatedApp?: { readonly app?: string; readonly code?: string } | null;
    readonly condition?: { readonly field?: string; readonly relatedField?: string };
  } | null;
  readonly [key: string]: unknown;
}

export interface KintoneFormFieldsResponse {
  readonly properties: Readonly<Record<string, KintoneFieldProperty>>;
  readonly revision: string;
}

export interface KintoneRecordField {
  readonly type?: string;
  readonly value: unknown;
}

export type KintoneRecord = Readonly<Record<string, KintoneRecordField>>;

export interface KintoneRequest {
  readonly method: "GET" | "POST" | "PUT" | "DELETE";
  readonly path: string;
  readonly body?: unknown;
}

export interface KintoneTransport {
  request<T>(request: KintoneRequest): Promise<T>;
}

export interface KintoneQueryCompilation {
  readonly query: string;
  readonly fields?: readonly string[];
  readonly pageSize: number;
  readonly strategy: "records" | "offset" | "cursor";
}

export interface KintoneQueryResult<T = Readonly<Record<string, unknown>>> {
  readonly records: readonly T[];
  readonly plan: ExecutionPlan;
}

export interface KintoneMutationResult {
  readonly affected: number;
  readonly ids?: readonly string[];
  readonly revisions?: readonly string[];
  readonly plan: ExecutionPlan;
}

export interface KintonePreparedQuery {
  readonly operation: QueryIR;
  readonly plan: ExecutionPlan;
  readonly compilation: KintoneQueryCompilation;
}

export interface KintoneSchemaResult {
  readonly schema: ModelSchema;
  readonly revision: string;
}

export class KintoneCompatibilityError extends Error {
  readonly diagnostics: readonly Diagnostic[];

  constructor(message: string, diagnostics: readonly Diagnostic[]) {
    super(message);
    this.name = "KintoneCompatibilityError";
    this.diagnostics = diagnostics;
  }
}
