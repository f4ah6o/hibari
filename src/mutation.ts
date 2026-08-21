import type { FilterExpression, ScalarValue } from "./query.js";

export type RecordValue = Readonly<Record<string, unknown>>;

export interface ConcurrencyCondition {
  readonly field: string;
  readonly expected: ScalarValue;
}

export interface InsertMutation {
  readonly kind: "mutation";
  readonly operation: "insert";
  readonly model: string;
  readonly record: RecordValue;
}

export interface InsertManyMutation {
  readonly kind: "mutation";
  readonly operation: "insertMany";
  readonly model: string;
  readonly records: readonly RecordValue[];
}

export interface UpdateMutation {
  readonly kind: "mutation";
  readonly operation: "update";
  readonly model: string;
  readonly where: FilterExpression;
  readonly changes: RecordValue;
  readonly concurrency?: ConcurrencyCondition;
}

export interface UpdateManyMutation {
  readonly kind: "mutation";
  readonly operation: "updateMany";
  readonly model: string;
  readonly where: FilterExpression;
  readonly changes: RecordValue;
}

export interface DeleteMutation {
  readonly kind: "mutation";
  readonly operation: "delete";
  readonly model: string;
  readonly where: FilterExpression;
  readonly concurrency?: ConcurrencyCondition;
}

export interface UpsertMutation {
  readonly kind: "mutation";
  readonly operation: "upsert";
  readonly model: string;
  readonly where: FilterExpression;
  readonly create: RecordValue;
  readonly update: RecordValue;
  readonly concurrency?: ConcurrencyCondition;
}

export type MutationIR =
  | InsertMutation
  | InsertManyMutation
  | UpdateMutation
  | UpdateManyMutation
  | DeleteMutation
  | UpsertMutation;

export type MutationOperation = MutationIR["operation"];
