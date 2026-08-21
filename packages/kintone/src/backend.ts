import type {
  DatastoreRuntime,
  Diagnostic,
  ExecutionPlan,
  FilterExpression,
  ModelSchema,
  MutationIR,
  QueryIR,
  RecordValue
} from "@hibari/core";
import { planMutation } from "@hibari/core";
import { kintoneCapabilities } from "./capabilities.js";
import {
  decodeKintoneRecord,
  encodeKintoneRecord,
  kintoneFieldCode,
  kintoneFieldName
} from "./codec.js";
import { prepareKintoneQuery } from "./query.js";
import { schemaFromFormFields } from "./schema.js";
import type {
  KintoneFormFieldsResponse,
  KintoneModelBinding,
  KintoneMutationResult,
  KintoneQueryResult,
  KintoneRecord,
  KintoneTransport
} from "./types.js";
import { KintoneCompatibilityError } from "./types.js";

interface RecordsResponse {
  readonly records: readonly KintoneRecord[];
  readonly totalCount?: string | null;
}

interface CursorCreateResponse {
  readonly id: string;
  readonly totalCount: string;
}

interface CursorReadResponse {
  readonly records: readonly KintoneRecord[];
  readonly next: boolean;
}

interface WriteResponse {
  readonly id?: string;
  readonly revision?: string;
  readonly ids?: readonly string[];
  readonly revisions?: readonly string[];
  readonly records?: readonly { readonly id: string; readonly revision: string }[];
}

function path(binding: KintoneModelBinding, endpoint: string): string {
  return binding.guestSpaceId === undefined
    ? `/k/v1/${endpoint}`
    : `/k/guest/${binding.guestSpaceId}/v1/${endpoint}`;
}

function chunks<T>(values: readonly T[], size: number): readonly (readonly T[])[] {
  const result: T[][] = [];
  for (let index = 0; index < values.length; index += size) {
    result.push(values.slice(index, index + size));
  }
  return result;
}

function backendDiagnostic(code: string, message: string, capability: string): Diagnostic {
  return {
    code,
    severity: "error",
    operation: "mutation",
    target: "kintone",
    reason: "mutation cannot be represented with equivalent kintone semantics",
    message,
    capability
  };
}

function assertMutationPlan(mutation: MutationIR): ExecutionPlan {
  const plan = planMutation(mutation, kintoneCapabilities);
  if (plan.classification === "unsupported") {
    throw new KintoneCompatibilityError(
      plan.diagnostics[0]?.message ?? "Mutation is unsupported by Kintone.",
      plan.diagnostics
    );
  }
  return plan;
}

function exactEquality(
  where: FilterExpression
): { readonly field: string; readonly value: string | number | boolean | null } | undefined {
  if (where?.op === "eq") {
    return { field: where.field, value: where.value };
  }
  return undefined;
}

function systemNumber(value: string | undefined): string | number | undefined {
  if (value === undefined) {
    return undefined;
  }
  const parsed = Number(value);
  return Number.isNaN(parsed) ? value : parsed;
}

function withSystemFields(
  record: RecordValue,
  idField: string,
  revisionField: string,
  id: string | undefined,
  revision: string | undefined
): RecordValue {
  return {
    ...record,
    ...(id === undefined ? {} : { [idField]: systemNumber(id) }),
    ...(revision === undefined ? {} : { [revisionField]: systemNumber(revision) })
  };
}

export class KintoneBackend implements DatastoreRuntime {
  readonly #transport: KintoneTransport;
  readonly #bindings: ReadonlyMap<string, KintoneModelBinding>;
  readonly #schemas = new Map<string, ModelSchema>();

  constructor(transport: KintoneTransport, bindings: readonly KintoneModelBinding[]) {
    this.#transport = transport;
    this.#bindings = new Map(bindings.map((binding) => [binding.model, binding]));
  }

  #binding(model: string): KintoneModelBinding {
    const binding = this.#bindings.get(model);
    if (binding === undefined) {
      throw new Error(`No Kintone binding is configured for model '${model}'.`);
    }
    return binding;
  }

  async introspect(model: string) {
    const binding = this.#binding(model);
    const response = await this.#transport.request<KintoneFormFieldsResponse>({
      method: "GET",
      path: path(binding, "app/form/fields.json"),
      body: { app: binding.app }
    });
    const result = schemaFromFormFields(response, binding);
    this.#schemas.set(model, result.schema);
    return result;
  }

  prepareQuery(query: QueryIR) {
    return prepareKintoneQuery(query, this.#binding(query.model));
  }

  async query(query: QueryIR): Promise<KintoneQueryResult> {
    const binding = this.#binding(query.model);
    const prepared = prepareKintoneQuery(query, binding);
    const schema = this.#schemas.get(query.model);
    const requested = query.limit;

    if (prepared.compilation.strategy === "records") {
      const response = await this.#transport.request<RecordsResponse>({
        method: "GET",
        path: path(binding, "records.json"),
        body: {
          app: binding.app,
          query: prepared.compilation.query,
          ...(prepared.compilation.fields === undefined
            ? {}
            : { fields: prepared.compilation.fields })
        }
      });
      return {
        records: response.records.map((record) => decodeKintoneRecord(record, binding, schema)),
        plan: prepared.plan
      };
    }

    if (prepared.compilation.strategy === "offset") {
      const records: KintoneRecord[] = [];
      const requested = query.limit!;
      let currentOffset = query.offset ?? 0;
      while (records.length < requested) {
        const pageLimit = Math.min(prepared.compilation.pageSize, requested - records.length);
        const queryWithPage = [
          prepared.compilation.query,
          `limit ${pageLimit}`,
          currentOffset > 0 ? `offset ${currentOffset}` : ""
        ]
          .filter((part) => part.length > 0)
          .join(" ");
        const response = await this.#transport.request<RecordsResponse>({
          method: "GET",
          path: path(binding, "records.json"),
          body: {
            app: binding.app,
            query: queryWithPage,
            ...(prepared.compilation.fields === undefined
              ? {}
              : { fields: prepared.compilation.fields })
          }
        });
        records.push(...response.records);
        if (response.records.length < pageLimit) {
          break;
        }
        currentOffset += pageLimit;
      }
      return {
        records: records.map((record) => decodeKintoneRecord(record, binding, schema)),
        plan: prepared.plan
      };
    }

    const cursor = await this.#transport.request<CursorCreateResponse>({
      method: "POST",
      path: path(binding, "records/cursor.json"),
      body: {
        app: binding.app,
        query: prepared.compilation.query,
        size: prepared.compilation.pageSize,
        ...(prepared.compilation.fields === undefined
          ? {}
          : { fields: prepared.compilation.fields })
      }
    });

    const records: KintoneRecord[] = [];
    let exhausted = false;
    try {
      let next = true;
      while (next && (requested === undefined || records.length < requested)) {
        const response = await this.#transport.request<CursorReadResponse>({
          method: "GET",
          path: path(binding, "records/cursor.json"),
          body: { id: cursor.id }
        });
        records.push(...response.records);
        next = response.next;
      }
      exhausted = !next;
    } finally {
      if (!exhausted) {
        await this.#transport.request<Record<string, never>>({
          method: "DELETE",
          path: path(binding, "records/cursor.json"),
          body: { id: cursor.id }
        });
      }
    }

    const selected = requested === undefined ? records : records.slice(0, requested);
    return {
      records: selected.map((record) => decodeKintoneRecord(record, binding, schema)),
      plan: prepared.plan
    };
  }

  async #matchingRecords(
    model: string,
    where: Extract<MutationIR, { readonly operation: "update" }>["where"],
    limit?: number
  ): Promise<readonly RecordValue[]> {
    const binding = this.#binding(model);
    return (
      await this.query({
        kind: "query",
        model,
        projection: [
          kintoneFieldName(binding, "$id"),
          kintoneFieldName(binding, "$revision")
        ],
        filter: where,
        ...(limit === undefined ? {} : { limit })
      })
    ).records;
  }

  async mutate(mutation: MutationIR): Promise<KintoneMutationResult> {
    const binding = this.#binding(mutation.model);
    const plan = assertMutationPlan(mutation);
    const schema = this.#schemas.get(mutation.model);
    const idField = kintoneFieldName(binding, "$id");
    const revisionField = kintoneFieldName(binding, "$revision");

    if (
      (mutation.operation === "update" ||
        mutation.operation === "delete" ||
        mutation.operation === "upsert") &&
      mutation.concurrency !== undefined &&
      kintoneFieldCode(binding, mutation.concurrency.field) !== "$revision"
    ) {
      throw new KintoneCompatibilityError(
        "Kintone optimistic concurrency uses the revision token mapped by the model binding.",
        [
          backendDiagnostic(
            "HIB-KINTONE-REVISION-001",
            `Concurrency field '${mutation.concurrency.field}' is not mapped to the Kintone revision token.`,
            "mutation.optimisticConcurrency"
          )
        ]
      );
    }

    if (mutation.operation === "insert") {
      const response = await this.#transport.request<WriteResponse>({
        method: "POST",
        path: path(binding, "record.json"),
        body: {
          app: binding.app,
          record: encodeKintoneRecord(mutation.record, binding, schema)
        }
      });
      return {
        affected: 1,
        records: [
          withSystemFields(
            mutation.record,
            idField,
            revisionField,
            response.id,
            response.revision
          )
        ],
        ...(response.id === undefined ? {} : { ids: [response.id] }),
        ...(response.revision === undefined ? {} : { revisions: [response.revision] }),
        plan
      };
    }

    if (mutation.operation === "insertMany") {
      const ids: string[] = [];
      const revisions: string[] = [];
      for (const batch of chunks(mutation.records, 100)) {
        const response = await this.#transport.request<WriteResponse>({
          method: "POST",
          path: path(binding, "records.json"),
          body: {
            app: binding.app,
            records: batch.map((record) => encodeKintoneRecord(record, binding, schema))
          }
        });
        ids.push(...(response.ids ?? []));
        revisions.push(...(response.revisions ?? []));
      }
      return {
        affected: mutation.records.length,
        records: mutation.records.map((record, index) =>
          withSystemFields(record, idField, revisionField, ids[index], revisions[index])
        ),
        ids,
        revisions,
        plan
      };
    }

    if (mutation.operation === "upsert") {
      const equality = exactEquality(mutation.where);
      const uniqueFields = new Set([
        ...(binding.uniqueFields ?? []),
        ...(schema?.uniqueConstraints?.flatMap((constraint) =>
          constraint.fields.length === 1 ? [constraint.fields[0]!] : []
        ) ?? [])
      ]);
      if (equality === undefined || !uniqueFields.has(equality.field) || equality.value === null) {
        throw new KintoneCompatibilityError(
          "Kintone upsert requires an exact equality on a configured unique field.",
          [
            backendDiagnostic(
              "HIB-KINTONE-UPSERT-001",
              "Upsert requires where: eq on a field backed by a Kintone unique Text or Number field.",
              "mutation.upsert.updateKey"
            )
          ]
        );
      }

      const existing = (
        await this.query({
          kind: "query",
          model: mutation.model,
          projection: [idField, revisionField],
          filter: mutation.where,
          limit: 2
        })
      ).records;

      if (existing.length > 1) {
        throw new KintoneCompatibilityError(
          "Configured upsert key is not unique in the backend data.",
          [
            backendDiagnostic(
              "HIB-KINTONE-UPSERT-002",
              `Unique selector '${equality.field}' resolved to more than one Kintone record.`,
              "mutation.upsert.updateKey"
            )
          ]
        );
      }

      if (existing.length === 0) {
        const response = await this.#transport.request<WriteResponse>({
          method: "POST",
          path: path(binding, "record.json"),
          body: {
            app: binding.app,
            record: encodeKintoneRecord(mutation.create, binding, schema)
          }
        });
        return {
          affected: 1,
          records: [
            withSystemFields(
              mutation.create,
              idField,
              revisionField,
              response.id,
              response.revision
            )
          ],
          ...(response.id === undefined ? {} : { ids: [response.id] }),
          ...(response.revision === undefined ? {} : { revisions: [response.revision] }),
          plan
        };
      }

      const current = existing[0]!;
      const response = await this.#transport.request<WriteResponse>({
        method: "PUT",
        path: path(binding, "record.json"),
        body: {
          app: binding.app,
          id: current[idField],
          record: encodeKintoneRecord(mutation.update, binding, schema),
          ...(mutation.concurrency === undefined
            ? {}
            : { revision: mutation.concurrency.expected })
        }
      });
      const updatedRecord: RecordValue = {
        ...current,
        ...mutation.update,
        ...(response.revision === undefined
          ? {}
          : { [revisionField]: systemNumber(response.revision) })
      };
      return {
        affected: 1,
        records: [updatedRecord],
        ids: [String(current[idField])],
        ...(response.revision === undefined ? {} : { revisions: [response.revision] }),
        plan
      };
    }

    const matches = await this.#matchingRecords(
      mutation.model,
      mutation.where,
      mutation.operation === "updateMany" ? undefined : 2
    );
    if (mutation.operation !== "updateMany" && matches.length > 1) {
      throw new KintoneCompatibilityError(
        `Singular ${mutation.operation} matched ${matches.length} records.`,
        [
          backendDiagnostic(
            "HIB-KINTONE-MUTATION-001",
            `Singular ${mutation.operation} requires a selector that resolves to at most one Kintone record.`,
            `mutation.operations.${mutation.operation}`
          )
        ]
      );
    }

    if (matches.length === 0) {
      return { affected: 0, records: [], plan };
    }

    if (mutation.operation === "delete") {
      const ids = matches.map((record) => String(record[idField]));
      const revisions = matches.map((record) =>
        mutation.concurrency === undefined ? -1 : mutation.concurrency.expected
      );
      for (const [index, batch] of chunks(ids, 100).entries()) {
        const start = index * 100;
        await this.#transport.request<Record<string, never>>({
          method: "DELETE",
          path: path(binding, "records.json"),
          body: {
            app: binding.app,
            ids: batch,
            revisions: revisions.slice(start, start + batch.length)
          }
        });
      }
      return { affected: ids.length, records: matches, ids, plan };
    }

    const changes = encodeKintoneRecord(mutation.changes, binding, schema);
    const returnedRevisions = new Map<string, string>();
    for (const batch of chunks(matches, 100)) {
      const response = await this.#transport.request<WriteResponse>({
        method: "PUT",
        path: path(binding, "records.json"),
        body: {
          app: binding.app,
          records: batch.map((record) => ({
            id: record[idField],
            record: changes,
            ...((mutation.operation === "update" && mutation.concurrency !== undefined)
              ? { revision: mutation.concurrency.expected }
              : {})
          }))
        }
      });
      for (const returned of response.records ?? []) {
        returnedRevisions.set(String(returned.id), returned.revision);
      }
    }
    const records = matches.map((record) => {
      const revision = returnedRevisions.get(String(record[idField]));
      return {
        ...record,
        ...mutation.changes,
        ...(revision === undefined ? {} : { [revisionField]: systemNumber(revision) })
      };
    });
    return {
      affected: matches.length,
      records,
      ids: matches.map((record) => String(record[idField])),
      revisions: records
        .map((record) => record[revisionField])
        .filter((value): value is string | number => typeof value === "string" || typeof value === "number")
        .map(String),
      plan
    };
  }
}
