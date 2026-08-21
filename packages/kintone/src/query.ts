import type { Diagnostic, FilterExpression, QueryIR, ScalarValue } from "@hibari/core";
import { planQuery } from "@hibari/core";
import { kintoneCapabilities } from "./capabilities.js";
import { kintoneFieldCode } from "./codec.js";
import type {
  KintoneModelBinding,
  KintonePreparedQuery,
  KintoneQueryCompilation
} from "./types.js";
import { KintoneCompatibilityError } from "./types.js";

const operatorMap = {
  eq: "=",
  ne: "!=",
  gt: ">",
  gte: ">=",
  lt: "<",
  lte: "<="
} as const;

function diagnostic(code: string, message: string, capability: string, path: string): Diagnostic {
  return {
    code,
    severity: "error",
    operation: "query",
    target: "kintone",
    reason: "query cannot be represented with equivalent kintone semantics",
    message,
    capability,
    path
  };
}

function escapeString(value: string): string {
  return value.replace(/\\/g, "\\\\").replace(/"/g, '\\"');
}

function compileValue(value: ScalarValue, path: string): string {
  if (value === null) {
    throw new KintoneCompatibilityError("Null comparison is not portable to Kintone query semantics.", [
      diagnostic(
        "HIB-KINTONE-QUERY-001",
        "Null comparison is not represented as '=' / '!=' in Kintone query strings.",
        "query.filters",
        path
      )
    ]);
  }
  if (typeof value === "string") {
    return `"${escapeString(value)}"`;
  }
  if (typeof value === "boolean") {
    return value ? "true" : "false";
  }
  return String(value);
}

export function compileKintoneFilter(
  filter: FilterExpression,
  binding: KintoneModelBinding,
  path = "filter"
): string {
  if ("expressions" in filter) {
    if (filter.expressions.length === 0) {
      throw new KintoneCompatibilityError("Empty boolean expression is not supported.", [
        diagnostic(
          "HIB-KINTONE-QUERY-002",
          "Kintone query compilation requires at least one expression in and/or groups.",
          "query.filters",
          path
        )
      ]);
    }
    return `(${filter.expressions
      .map((expression, index) =>
        compileKintoneFilter(expression, binding, `${path}.expressions[${index}]`)
      )
      .join(` ${filter.op} `)})`;
  }

  const field = kintoneFieldCode(binding, filter.field);
  if (filter.op === "in") {
    if (filter.values.length === 0) {
      throw new KintoneCompatibilityError("Empty IN expression is not supported.", [
        diagnostic(
          "HIB-KINTONE-QUERY-003",
          "Kintone 'in' requires one or more values.",
          "query.filters.in",
          path
        )
      ]);
    }
    return `${field} in (${filter.values
      .map((value, index) => compileValue(value, `${path}.values[${index}]`))
      .join(", ")})`;
  }

  return `${field} ${operatorMap[filter.op]} ${compileValue(filter.value, `${path}.value`)}`;
}

function compileCursor(query: QueryIR, binding: KintoneModelBinding): string | undefined {
  if (query.cursor === undefined) {
    return undefined;
  }

  const firstOrdering = query.ordering?.[0];
  if (firstOrdering !== undefined && firstOrdering.field !== query.cursor.field) {
    throw new KintoneCompatibilityError(
      "Cursor field must match the leading ordering field for Kintone seek semantics.",
      [
        diagnostic(
          "HIB-KINTONE-CURSOR-001",
          `Cursor field '${query.cursor.field}' does not match leading ordering field '${firstOrdering.field}'.`,
          "query.cursor",
          "cursor"
        )
      ]
    );
  }

  const direction = query.cursor.direction ?? "after";
  const operator = direction === "after" ? ">" : "<";
  return `${kintoneFieldCode(binding, query.cursor.field)} ${operator} ${compileValue(
    query.cursor.value,
    "cursor.value"
  )}`;
}

function ordering(query: QueryIR, binding: KintoneModelBinding): string | undefined {
  if (query.ordering !== undefined && query.ordering.length > 0) {
    return query.ordering
      .map(({ field, direction }) => `${kintoneFieldCode(binding, field)} ${direction}`)
      .join(", ");
  }
  if (query.cursor !== undefined) {
    const direction = (query.cursor.direction ?? "after") === "after" ? "asc" : "desc";
    return `${kintoneFieldCode(binding, query.cursor.field)} ${direction}`;
  }
  return undefined;
}

export function compileKintoneQuery(
  query: QueryIR,
  binding: KintoneModelBinding
): KintoneQueryCompilation {
  const filterParts: string[] = [];
  if (query.filter !== undefined) {
    filterParts.push(compileKintoneFilter(query.filter, binding));
  }
  const cursor = compileCursor(query, binding);
  if (cursor !== undefined) {
    filterParts.push(cursor);
  }

  const clauses: string[] = [];
  if (filterParts.length > 0) {
    clauses.push(filterParts.length === 1 ? filterParts[0]! : `(${filterParts.join(" and ")})`);
  }
  const orderBy = ordering(query, binding);
  if (orderBy !== undefined) {
    clauses.push(`order by ${orderBy}`);
  }

  if (query.limit === undefined && query.offset !== undefined && query.offset > 0) {
    throw new KintoneCompatibilityError(
      "Unbounded offset reads cannot be guaranteed within Kintone's offset ceiling.",
      [
        diagnostic(
          "HIB-KINTONE-OFFSET-001",
          "Use a finite limit or cursor/seek pagination instead of an unbounded offset read.",
          "query.offset",
          "offset"
        )
      ]
    );
  }

  const requestedLimit = query.limit;
  const pageSize = Math.min(500, requestedLimit === undefined ? 500 : Math.max(1, requestedLimit));
  const offset = query.offset ?? 0;
  let strategy: KintoneQueryCompilation["strategy"];

  if (requestedLimit === undefined || (requestedLimit > 500 && offset === 0)) {
    strategy = "cursor";
  } else if (requestedLimit > 500) {
    const lastPageOffset = offset + Math.floor((requestedLimit - 1) / 500) * 500;
    if (lastPageOffset > 10_000) {
      throw new KintoneCompatibilityError(
        "Requested offset pagination would cross Kintone's 10,000 offset ceiling.",
        [
          diagnostic(
            "HIB-KINTONE-OFFSET-002",
            `The final page would require offset ${lastPageOffset}, above Kintone's maximum 10000.`,
            "query.offset",
            "offset"
          )
        ]
      );
    }
    strategy = "offset";
  } else {
    strategy = "records";
  }

  if (strategy === "records") {
    clauses.push(`limit ${pageSize}`);
    if (offset > 0) {
      clauses.push(`offset ${offset}`);
    }
  }

  const fields = query.projection?.map((field) => kintoneFieldCode(binding, field));
  return {
    query: clauses.join(" "),
    ...(fields === undefined ? {} : { fields }),
    pageSize,
    strategy
  };
}

export function prepareKintoneQuery(
  query: QueryIR,
  binding: KintoneModelBinding
): KintonePreparedQuery {
  const corePlan = planQuery(query, kintoneCapabilities);
  if (corePlan.classification === "unsupported") {
    throw new KintoneCompatibilityError(
      corePlan.diagnostics[0]?.message ?? "Query is unsupported by Kintone.",
      corePlan.diagnostics
    );
  }
  const compilation = compileKintoneQuery(query, binding);

  if (query.limit === undefined) {
    const warning: Diagnostic = {
      code: "HIB-KINTONE-COST-001",
      severity: "warning",
      operation: "query",
      target: query.model,
      reason: "unbounded query may consume a large portion of the backend request budget",
      message: "Unbounded Kintone reads use cursor pagination and may consume many REST API requests.",
      capability: "limits.requestBudget",
      hint: "Add a finite limit when the caller does not need the complete result set."
    };
    return {
      operation: query,
      plan: {
        ...corePlan,
        classification: "expensive",
        diagnostics: [...corePlan.diagnostics, warning]
      },
      compilation
    };
  }

  const pages = Math.max(1, Math.ceil(query.limit / 500));
  const estimatedRequests =
    compilation.strategy === "cursor" ? pages + 2 : pages;
  return {
    operation: query,
    plan: { ...corePlan, estimatedRequests },
    compilation
  };
}
