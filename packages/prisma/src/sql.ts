import type {
  FilterExpression,
  MutationIR,
  QueryIR,
  ScalarValue
} from "@hibari/core";
import {
  prismaCompatibilityError,
  prismaDiagnosticCodes
} from "./diagnostics.js";

export interface PrismaSqlInput {
  readonly sql: string;
  readonly args: readonly unknown[];
}

export interface ProjectionColumn {
  readonly field: string;
  readonly output: string;
}

export interface TranslatedPrismaStatement {
  readonly operation: QueryIR | MutationIR;
  readonly projection?: readonly ProjectionColumn[];
  readonly returning?: readonly ProjectionColumn[];
}

type TokenKind = "word" | "identifier" | "number" | "string" | "param" | "symbol";

interface Token {
  readonly kind: TokenKind;
  readonly value: string;
  readonly raw: string;
}

function unsupported(
  sql: string,
  message: string,
  code = prismaDiagnosticCodes.unsupportedSql,
  capability = "prisma.sql"
): never {
  throw prismaCompatibilityError(code, message, capability, sql);
}

function unquote(raw: string): string {
  if (raw.startsWith('"')) {
    return raw.slice(1, -1).replace(/""/g, '"');
  }
  if (raw.startsWith("`")) {
    return raw.slice(1, -1).replace(/``/g, "`");
  }
  if (raw.startsWith("[")) {
    return raw.slice(1, -1).replace(/]]/g, "]");
  }
  return raw;
}

function tokenize(sql: string): readonly Token[] {
  const tokens: Token[] = [];
  let index = 0;

  const push = (kind: TokenKind, raw: string, value = raw) => {
    tokens.push({ kind, raw, value });
  };

  while (index < sql.length) {
    const char = sql[index]!;

    if (/\s/.test(char)) {
      index += 1;
      continue;
    }

    if (char === "-" && sql[index + 1] === "-") {
      while (index < sql.length && sql[index] !== "\n") {
        index += 1;
      }
      continue;
    }

    if (char === "/" && sql[index + 1] === "*") {
      const end = sql.indexOf("*/", index + 2);
      if (end < 0) {
        unsupported(sql, "Unterminated SQL comment.");
      }
      index = end + 2;
      continue;
    }

    if (char === '"' || char === "`" || char === "[") {
      const close = char === "[" ? "]" : char;
      let end = index + 1;
      while (end < sql.length) {
        if (sql[end] === close) {
          if (char !== "[" && sql[end + 1] === close) {
            end += 2;
            continue;
          }
          break;
        }
        end += 1;
      }
      if (end >= sql.length) {
        unsupported(sql, "Unterminated quoted identifier.");
      }
      const raw = sql.slice(index, end + 1);
      push("identifier", raw, unquote(raw));
      index = end + 1;
      continue;
    }

    if (char === "'") {
      let end = index + 1;
      while (end < sql.length) {
        if (sql[end] === "'") {
          if (sql[end + 1] === "'") {
            end += 2;
            continue;
          }
          break;
        }
        end += 1;
      }
      if (end >= sql.length) {
        unsupported(sql, "Unterminated SQL string literal.");
      }
      const raw = sql.slice(index, end + 1);
      push("string", raw, raw.slice(1, -1).replace(/''/g, "'"));
      index = end + 1;
      continue;
    }

    if (char === "?") {
      let end = index + 1;
      while (end < sql.length && /\d/.test(sql[end]!)) {
        end += 1;
      }
      const raw = sql.slice(index, end);
      push("param", raw);
      index = end;
      continue;
    }

    if (char === "$" && /\d/.test(sql[index + 1] ?? "")) {
      let end = index + 1;
      while (end < sql.length && /\d/.test(sql[end]!)) {
        end += 1;
      }
      const raw = sql.slice(index, end);
      push("param", raw);
      index = end;
      continue;
    }

    const two = sql.slice(index, index + 2);
    if (["<=", ">=", "<>", "!="].includes(two)) {
      push("symbol", two);
      index += 2;
      continue;
    }

    if ("(),.*=<>;+-".includes(char)) {
      push("symbol", char);
      index += 1;
      continue;
    }

    if (/\d/.test(char)) {
      let end = index + 1;
      while (end < sql.length && /[\d.]/.test(sql[end]!)) {
        end += 1;
      }
      const raw = sql.slice(index, end);
      push("number", raw);
      index = end;
      continue;
    }

    if (/[A-Za-z_]/.test(char)) {
      let end = index + 1;
      while (end < sql.length && /[A-Za-z0-9_$]/.test(sql[end]!)) {
        end += 1;
      }
      const raw = sql.slice(index, end);
      push("word", raw, raw);
      index = end;
      continue;
    }

    unsupported(sql, `Unsupported SQL token near '${sql.slice(index, index + 16)}'.`);
  }

  return tokens;
}

function upper(token: Token | undefined): string | undefined {
  return token?.value.toUpperCase();
}

class Parser {
  #index = 0;
  #nextArgument = 0;

  constructor(
    readonly sql: string,
    readonly tokens: readonly Token[],
    readonly args: readonly unknown[]
  ) {}

  peek(offset = 0): Token | undefined {
    return this.tokens[this.#index + offset];
  }

  eof(): boolean {
    return this.#index >= this.tokens.length;
  }

  consume(): Token {
    const token = this.tokens[this.#index];
    if (token === undefined) {
      unsupported(this.sql, "Unexpected end of SQL.");
    }
    this.#index += 1;
    return token;
  }

  matchWord(word: string): boolean {
    if (upper(this.peek()) === word.toUpperCase()) {
      this.#index += 1;
      return true;
    }
    return false;
  }

  expectWord(word: string): void {
    if (!this.matchWord(word)) {
      unsupported(this.sql, `Expected ${word}.`);
    }
  }

  matchSymbol(symbol: string): boolean {
    if (this.peek()?.raw === symbol) {
      this.#index += 1;
      return true;
    }
    return false;
  }

  expectSymbol(symbol: string): void {
    if (!this.matchSymbol(symbol)) {
      unsupported(this.sql, `Expected '${symbol}'.`);
    }
  }

  identifier(): string {
    const token = this.consume();
    if (token.kind !== "identifier" && token.kind !== "word") {
      unsupported(this.sql, `Expected identifier, found '${token.raw}'.`);
    }
    return token.value;
  }

  qualifiedIdentifier(): string {
    let name = this.identifier();
    while (this.matchSymbol(".")) {
      if (this.matchSymbol("*")) {
        return "*";
      }
      name = this.identifier();
    }
    return name;
  }

  scalarValue(): ScalarValue {
    const token = this.consume();
    if (token.kind === "param") {
      let argumentIndex: number;
      if (token.raw.startsWith("$")) {
        argumentIndex = Number(token.raw.slice(1)) - 1;
      } else if (token.raw.length > 1) {
        argumentIndex = Number(token.raw.slice(1)) - 1;
      } else {
        argumentIndex = this.#nextArgument;
        this.#nextArgument += 1;
      }

      const value = this.args[argumentIndex];
      if (
        value === null ||
        typeof value === "string" ||
        typeof value === "number" ||
        typeof value === "boolean"
      ) {
        return value;
      }
      if (typeof value === "bigint") {
        const converted = Number(value);
        if (!Number.isSafeInteger(converted)) {
          unsupported(this.sql, `Parameter ${argumentIndex + 1} exceeds the portable integer range.`);
        }
        return converted;
      }
      if (value instanceof Date) {
        return value.toISOString();
      }
      unsupported(
        this.sql,
        `Parameter ${argumentIndex + 1} has an unsupported scalar type.`
      );
    }

    if (token.kind === "string") {
      return token.value;
    }
    if (token.kind === "number") {
      return Number(token.value);
    }
    if (upper(token) === "NULL") {
      return null;
    }
    if (upper(token) === "TRUE") {
      return true;
    }
    if (upper(token) === "FALSE") {
      return false;
    }

    unsupported(this.sql, `Expected scalar value, found '${token.raw}'.`);
  }

  projectionUntil(stopWords: readonly string[]): readonly ProjectionColumn[] {
    const columns: ProjectionColumn[] = [];

    while (!this.eof() && !stopWords.includes(upper(this.peek()) ?? "")) {
      if (upper(this.peek()) === "DISTINCT") {
        unsupported(
          this.sql,
          "DISTINCT is outside the portable Prisma proof subset.",
          prismaDiagnosticCodes.unsupportedAggregate,
          "query.aggregate"
        );
      }

      const field = this.qualifiedIdentifier();
      if (field === "*") {
        unsupported(this.sql, "Wildcard projection is unsupported; Prisma should select explicit fields.");
      }
      if (this.matchSymbol("(")) {
        unsupported(
          this.sql,
          "SQL functions are outside the portable Prisma proof subset.",
          prismaDiagnosticCodes.unsupportedAggregate,
          "query.aggregate"
        );
      }

      let output = field;
      if (this.matchWord("AS")) {
        output = this.identifier();
      }
      columns.push({ field, output });

      if (!this.matchSymbol(",")) {
        break;
      }
    }

    return columns;
  }

  table(): string {
    return this.qualifiedIdentifier();
  }

  filter(): FilterExpression | undefined {
    return this.orExpression();
  }

  orExpression(): FilterExpression | undefined {
    const expressions: FilterExpression[] = [];
    const first = this.andExpression();
    if (first !== undefined) {
      expressions.push(first);
    }

    while (this.matchWord("OR")) {
      const expression = this.andExpression();
      if (expression !== undefined) {
        expressions.push(expression);
      }
    }

    if (expressions.length === 0) {
      return undefined;
    }
    return expressions.length === 1
      ? expressions[0]
      : { op: "or", expressions };
  }

  andExpression(): FilterExpression | undefined {
    const expressions: FilterExpression[] = [];
    const first = this.term();
    if (first !== undefined) {
      expressions.push(first);
    }

    while (this.matchWord("AND")) {
      const expression = this.term();
      if (expression !== undefined) {
        expressions.push(expression);
      }
    }

    if (expressions.length === 0) {
      return undefined;
    }
    return expressions.length === 1
      ? expressions[0]
      : { op: "and", expressions };
  }

  term(): FilterExpression | undefined {
    if (this.matchSymbol("(")) {
      const expression = this.orExpression();
      this.expectSymbol(")");
      return expression;
    }

    if (this.peek()?.kind === "number") {
      const left = this.scalarValue();
      const operator = this.consume().raw;
      const right = this.scalarValue();
      if (
        (operator === "=" && left === right) ||
        ((operator === "!=" || operator === "<>") && left !== right)
      ) {
        return undefined;
      }
      unsupported(
        this.sql,
        "Constant SQL predicates other than Prisma's true sentinel are unsupported."
      );
    }

    const field = this.qualifiedIdentifier();

    if (this.matchWord("IS")) {
      const not = this.matchWord("NOT");
      this.expectWord("NULL");
      return { op: not ? "ne" : "eq", field, value: null };
    }

    if (this.matchWord("IN")) {
      this.expectSymbol("(");
      const values: ScalarValue[] = [];
      if (!this.matchSymbol(")")) {
        do {
          values.push(this.scalarValue());
        } while (this.matchSymbol(","));
        this.expectSymbol(")");
      }
      return { op: "in", field, values };
    }

    const operator = this.consume().raw;
    const value = this.scalarValue();
    const operators: Readonly<Record<string, "eq" | "ne" | "gt" | "gte" | "lt" | "lte">> = {
      "=": "eq",
      "!=": "ne",
      "<>": "ne",
      ">": "gt",
      ">=": "gte",
      "<": "lt",
      "<=": "lte"
    };
    const mapped = operators[operator];
    if (mapped === undefined) {
      unsupported(this.sql, `Unsupported comparison operator '${operator}'.`);
    }
    return { op: mapped, field, value };
  }

  orderings(): readonly { readonly field: string; readonly direction: "asc" | "desc" }[] {
    const result: { field: string; direction: "asc" | "desc" }[] = [];
    do {
      const field = this.qualifiedIdentifier();
      let direction: "asc" | "desc" = "asc";
      if (this.matchWord("DESC")) {
        direction = "desc";
      } else {
        this.matchWord("ASC");
      }
      result.push({ field, direction });
    } while (this.matchSymbol(","));
    return result;
  }

  nonNegativeInteger(label: string): number | undefined {
    const value = this.scalarValue();
    if (typeof value !== "number" || !Number.isInteger(value)) {
      unsupported(this.sql, `${label} must be an integer.`);
    }
    return value < 0 ? undefined : value;
  }

  parseSelect(): TranslatedPrismaStatement {
    this.expectWord("SELECT");
    const projection = this.projectionUntil(["FROM"]);
    this.expectWord("FROM");
    const model = this.table();

    if (this.matchWord("AS")) {
      this.identifier();
    }

    if (
      this.matchWord("JOIN") ||
      this.matchWord("INNER") ||
      this.matchWord("LEFT") ||
      this.matchWord("RIGHT") ||
      this.matchWord("CROSS")
    ) {
      unsupported(
        this.sql,
        "JOIN is unsupported by the portable Hibari datastore profile.",
        prismaDiagnosticCodes.unsupportedJoin,
        "query.join"
      );
    }

    let filter: FilterExpression | undefined;
    let ordering: readonly { readonly field: string; readonly direction: "asc" | "desc" }[] | undefined;
    let limit: number | undefined;
    let offset: number | undefined;

    if (this.matchWord("WHERE")) {
      filter = this.filter();
    }

    if (this.matchWord("GROUP")) {
      unsupported(
        this.sql,
        "GROUP BY / aggregates are unsupported.",
        prismaDiagnosticCodes.unsupportedAggregate,
        "query.aggregate"
      );
    }

    if (this.matchWord("ORDER")) {
      this.expectWord("BY");
      ordering = this.orderings();
    }
    if (this.matchWord("LIMIT")) {
      limit = this.nonNegativeInteger("LIMIT");
    }
    if (this.matchWord("OFFSET")) {
      offset = this.nonNegativeInteger("OFFSET");
    }

    this.matchSymbol(";");
    if (!this.eof()) {
      unsupported(this.sql, `Unsupported SELECT suffix near '${this.peek()?.raw}'.`);
    }

    const operation: QueryIR = {
      kind: "query",
      model,
      projection: projection.map((column) => column.field),
      ...(filter === undefined ? {} : { filter }),
      ...(ordering === undefined ? {} : { ordering }),
      ...(limit === undefined ? {} : { limit }),
      ...(offset === undefined ? {} : { offset })
    };

    return { operation, projection };
  }

  parseReturning(): readonly ProjectionColumn[] | undefined {
    if (!this.matchWord("RETURNING")) {
      return undefined;
    }
    return this.projectionUntil([]);
  }

  parseInsert(): TranslatedPrismaStatement {
    this.expectWord("INSERT");
    if (this.matchWord("OR")) {
      unsupported(this.sql, "INSERT OR ... is outside the Prisma proof subset.");
    }
    this.expectWord("INTO");
    const model = this.table();
    this.expectSymbol("(");
    const fields: string[] = [];
    do {
      fields.push(this.qualifiedIdentifier());
    } while (this.matchSymbol(","));
    this.expectSymbol(")");
    this.expectWord("VALUES");

    const rows: Readonly<Record<string, unknown>>[] = [];
    do {
      this.expectSymbol("(");
      const row: Record<string, unknown> = {};
      for (let index = 0; index < fields.length; index += 1) {
        if (index > 0) {
          this.expectSymbol(",");
        }
        row[fields[index]!] = this.scalarValue();
      }
      this.expectSymbol(")");
      rows.push(row);
    } while (this.matchSymbol(","));

    if (this.matchWord("ON")) {
      unsupported(this.sql, "ON CONFLICT/upsert SQL is not yet in the Prisma proof subset.");
    }

    const returning = this.parseReturning();
    this.matchSymbol(";");
    if (!this.eof()) {
      unsupported(this.sql, `Unsupported INSERT suffix near '${this.peek()?.raw}'.`);
    }

    const operation: MutationIR = rows.length === 1
      ? { kind: "mutation", operation: "insert", model, record: rows[0]! }
      : { kind: "mutation", operation: "insertMany", model, records: rows };

    return { operation, ...(returning === undefined ? {} : { returning }) };
  }

  parseUpdate(): TranslatedPrismaStatement {
    this.expectWord("UPDATE");
    const model = this.table();
    this.expectWord("SET");
    const changes: Record<string, unknown> = {};
    do {
      const field = this.qualifiedIdentifier();
      this.expectSymbol("=");
      changes[field] = this.scalarValue();
    } while (this.matchSymbol(","));

    this.expectWord("WHERE");
    const where = this.filter();
    if (where === undefined) {
      unsupported(this.sql, "UPDATE requires a concrete filter.");
    }

    const returning = this.parseReturning();
    this.matchSymbol(";");
    if (!this.eof()) {
      unsupported(this.sql, `Unsupported UPDATE suffix near '${this.peek()?.raw}'.`);
    }

    return {
      operation: { kind: "mutation", operation: "update", model, where, changes },
      ...(returning === undefined ? {} : { returning })
    };
  }

  parseDelete(): TranslatedPrismaStatement {
    this.expectWord("DELETE");
    this.expectWord("FROM");
    const model = this.table();
    this.expectWord("WHERE");
    const where = this.filter();
    if (where === undefined) {
      unsupported(this.sql, "DELETE requires a concrete filter.");
    }

    const returning = this.parseReturning();
    this.matchSymbol(";");
    if (!this.eof()) {
      unsupported(this.sql, `Unsupported DELETE suffix near '${this.peek()?.raw}'.`);
    }

    return {
      operation: { kind: "mutation", operation: "delete", model, where },
      ...(returning === undefined ? {} : { returning })
    };
  }
}

export function translatePrismaSql(input: PrismaSqlInput): TranslatedPrismaStatement {
  const sql = input.sql.trim();

  if (/\bJOIN\b/i.test(sql)) {
    unsupported(
      sql,
      "JOIN is unsupported by the portable Hibari datastore profile.",
      prismaDiagnosticCodes.unsupportedJoin,
      "query.join"
    );
  }
  if (/\bGROUP\s+BY\b/i.test(sql) || /\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i.test(sql)) {
    unsupported(
      sql,
      "Aggregate SQL is unsupported by the portable Hibari datastore profile.",
      prismaDiagnosticCodes.unsupportedAggregate,
      "query.aggregate"
    );
  }
  if (/^(BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE)\b/i.test(sql)) {
    unsupported(
      sql,
      "Interactive/SQL transactions are unsupported by this datastore profile.",
      prismaDiagnosticCodes.unsupportedTransaction,
      "transaction.interactive"
    );
  }
  if (/^(CREATE|ALTER|DROP|PRAGMA|VACUUM)\b/i.test(sql)) {
    unsupported(
      sql,
      "Schema and migration SQL is not executable through the Hibari Prisma runtime.",
      prismaDiagnosticCodes.unsupportedSchemaSql,
      "schema.migration"
    );
  }

  const parser = new Parser(sql, tokenize(sql), input.args);
  const first = upper(parser.peek());
  if (first === "SELECT") {
    return parser.parseSelect();
  }
  if (first === "INSERT") {
    return parser.parseInsert();
  }
  if (first === "UPDATE") {
    return parser.parseUpdate();
  }
  if (first === "DELETE") {
    return parser.parseDelete();
  }

  unsupported(sql, `Unsupported SQL statement '${first ?? "<empty>"}'.`);
}
