import type {
  DatastoreRuntime,
  ModelSchema,
  ScalarType,
  SchemaIR
} from "@hibari/core";
import {
  ColumnTypeEnum,
  type ColumnType,
  type IsolationLevel,
  type SqlDriverAdapter,
  type SqlMigrationAwareDriverAdapterFactory,
  type SqlQuery,
  type SqlResultSet,
  type Transaction
} from "@prisma/driver-adapter-utils";
import {
  prismaCompatibilityError,
  prismaDiagnosticCodes
} from "./diagnostics.js";
import {
  translatePrismaSql,
  type ProjectionColumn
} from "./sql.js";

export interface PrismaHibariOptions {
  readonly runtime: DatastoreRuntime;
  readonly schema?: SchemaIR;
}

function modelSchema(schema: SchemaIR | undefined, name: string): ModelSchema | undefined {
  return schema?.models.find((model) => model.name === name);
}

function scalarType(
  schema: SchemaIR | undefined,
  model: string,
  field: string
): ScalarType | undefined {
  const candidate = modelSchema(schema, model)?.fields.find((item) => item.name === field);
  return candidate?.kind === "scalar" ? candidate.type : undefined;
}

function inferColumnType(value: unknown): ColumnType {
  if (typeof value === "boolean") {
    return ColumnTypeEnum.Boolean;
  }
  if (typeof value === "number") {
    return Number.isInteger(value) ? ColumnTypeEnum.Int32 : ColumnTypeEnum.Double;
  }
  if (typeof value === "bigint") {
    return ColumnTypeEnum.Int64;
  }
  if (value instanceof Date) {
    return ColumnTypeEnum.DateTime;
  }
  if (value instanceof Uint8Array) {
    return ColumnTypeEnum.Bytes;
  }
  if (value !== null && typeof value === "object") {
    return ColumnTypeEnum.Json;
  }
  return ColumnTypeEnum.Text;
}

function columnType(type: ScalarType | undefined, sample: unknown): ColumnType {
  switch (type) {
    case "integer":
      return ColumnTypeEnum.Int32;
    case "number":
      return ColumnTypeEnum.Double;
    case "boolean":
      return ColumnTypeEnum.Boolean;
    case "date":
      return ColumnTypeEnum.Date;
    case "datetime":
      return ColumnTypeEnum.DateTime;
    case "json":
      return ColumnTypeEnum.Json;
    case "bytes":
      return ColumnTypeEnum.Bytes;
    case "string":
      return ColumnTypeEnum.Text;
    default:
      return inferColumnType(sample);
  }
}

function resultSet(
  schema: SchemaIR | undefined,
  model: string,
  columns: readonly ProjectionColumn[],
  records: readonly Readonly<Record<string, unknown>>[],
  lastInsertId?: string
): SqlResultSet {
  return {
    columnNames: columns.map((column) => column.output),
    columnTypes: columns.map((column) =>
      columnType(scalarType(schema, model, column.field), records[0]?.[column.field])
    ),
    rows: records.map((record) => columns.map((column) => record[column.field] ?? null)),
    ...(lastInsertId === undefined ? {} : { lastInsertId })
  };
}

/**
 * Prisma's SQLite query compiler may serialize bigint pagination arguments as
 * decimal strings even though LIMIT/OFFSET are numeric SQL positions. Normalize
 * only placeholders immediately following LIMIT/OFFSET; application string
 * arguments remain untouched.
 */
function normalizePaginationArguments(sql: string, args: readonly unknown[]): readonly unknown[] {
  const normalized = [...args];
  let nextSequential = 0;
  const placeholders = /\?(\d*)|\$(\d+)/g;
  let match: RegExpExecArray | null;

  while ((match = placeholders.exec(sql)) !== null) {
    const raw = match[0];
    let argumentIndex: number;
    if (raw === "?") {
      argumentIndex = nextSequential;
      nextSequential += 1;
    } else {
      const digits = raw.slice(1);
      argumentIndex = Number(digits) - 1;
    }

    const prefix = sql.slice(Math.max(0, match.index - 32), match.index);
    if (!/\b(?:LIMIT|OFFSET)\s*$/i.test(prefix)) {
      continue;
    }

    const value = normalized[argumentIndex];
    if (typeof value === "string" && /^-?\d+$/.test(value)) {
      normalized[argumentIndex] = Number(value);
    }
  }

  return normalized;
}

function translateDriverSql(query: SqlQuery) {
  return translatePrismaSql({
    sql: query.sql,
    args: normalizePaginationArguments(query.sql, query.args)
  });
}

class HibariPrismaDriverAdapter implements SqlDriverAdapter {
  readonly provider = "sqlite" as const;
  readonly adapterName = "@hibari/prisma";
  readonly #runtime: DatastoreRuntime;
  readonly #schema: SchemaIR | undefined;

  constructor(runtime: DatastoreRuntime, schema: SchemaIR | undefined) {
    this.#runtime = runtime;
    this.#schema = schema;
  }

  async queryRaw(query: SqlQuery): Promise<SqlResultSet> {
    const translated = translateDriverSql(query);

    if (translated.operation.kind === "query") {
      const result = await this.#runtime.query(translated.operation);
      return resultSet(
        this.#schema,
        translated.operation.model,
        translated.projection ?? [],
        result.records
      );
    }

    const result = await this.#runtime.mutate(translated.operation);
    const returning = translated.returning ?? [];
    if (returning.length === 0) {
      return resultSet(
        this.#schema,
        translated.operation.model,
        [],
        [],
        result.ids?.[0]
      );
    }

    if (result.records === undefined) {
      throw prismaCompatibilityError(
        prismaDiagnosticCodes.missingReturningRecords,
        "The datastore runtime did not return mutation records required by Prisma RETURNING.",
        "mutation.returning",
        query.sql
      );
    }

    return resultSet(
      this.#schema,
      translated.operation.model,
      returning,
      result.records,
      result.ids?.[0]
    );
  }

  async executeRaw(query: SqlQuery): Promise<number> {
    const translated = translateDriverSql(query);
    if (translated.operation.kind === "query") {
      throw prismaCompatibilityError(
        prismaDiagnosticCodes.unsupportedSql,
        "A SELECT statement cannot be executed through Prisma executeRaw in the Hibari adapter.",
        "prisma.executeRaw",
        query.sql
      );
    }
    return (await this.#runtime.mutate(translated.operation)).affected;
  }

  async executeScript(script: string): Promise<void> {
    throw prismaCompatibilityError(
      prismaDiagnosticCodes.unsupportedSchemaSql,
      "Schema and migration scripts are not executable through the Hibari Prisma runtime.",
      "schema.migration",
      script
    );
  }

  async startTransaction(_isolationLevel?: IsolationLevel): Promise<Transaction> {
    throw prismaCompatibilityError(
      prismaDiagnosticCodes.unsupportedTransaction,
      "Interactive transactions are unsupported by the portable Hibari datastore profile.",
      "transaction.interactive"
    );
  }

  async dispose(): Promise<void> {}
}

/**
 * Prisma ORM 7 driver-adapter factory for Hibari.
 *
 * The factory intentionally advertises the SQLite provider because Prisma's
 * query compiler must lower Prisma Client operations to a concrete SQL dialect.
 * Only the deterministic portable subset parsed by @hibari/prisma is accepted;
 * this does not make SQLite semantics part of Hibari core.
 */
export class PrismaHibari implements SqlMigrationAwareDriverAdapterFactory {
  readonly provider = "sqlite" as const;
  readonly adapterName = "@hibari/prisma";

  constructor(readonly options: PrismaHibariOptions) {}

  async connect(): Promise<SqlDriverAdapter> {
    return new HibariPrismaDriverAdapter(this.options.runtime, this.options.schema);
  }

  async connectToShadowDb(): Promise<SqlDriverAdapter> {
    throw prismaCompatibilityError(
      prismaDiagnosticCodes.unsupportedSchemaSql,
      "Prisma Migrate and shadow databases are not supported by the Hibari runtime adapter.",
      "schema.migration"
    );
  }
}
