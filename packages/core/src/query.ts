export type ScalarValue = string | number | boolean | null;

export type FilterOperator = "eq" | "ne" | "gt" | "gte" | "lt" | "lte" | "in";

export type FilterExpression =
  | {
      readonly op: Exclude<FilterOperator, "in">;
      readonly field: string;
      readonly value: ScalarValue;
    }
  | {
      readonly op: "in";
      readonly field: string;
      readonly values: readonly ScalarValue[];
    }
  | {
      readonly op: "and" | "or";
      readonly expressions: readonly FilterExpression[];
    };

export interface Ordering {
  readonly field: string;
  readonly direction: "asc" | "desc";
}

export interface Cursor {
  readonly field: string;
  readonly value: ScalarValue;
  readonly direction?: "after" | "before";
}

export interface QueryIR {
  readonly kind: "query";
  readonly model: string;
  readonly projection?: readonly string[];
  readonly filter?: FilterExpression;
  readonly ordering?: readonly Ordering[];
  readonly limit?: number;
  readonly cursor?: Cursor;
  readonly offset?: number;
}

export function collectFilterOperators(filter: FilterExpression | undefined): readonly FilterOperator[] {
  if (filter === undefined) {
    return [];
  }

  if (filter.op === "and" || filter.op === "or") {
    return filter.expressions.flatMap((expression) => collectFilterOperators(expression));
  }

  return [filter.op];
}
