import type { ExecutionPlan } from "./plan.js";
import type { MutationIR, RecordValue } from "./mutation.js";
import type { QueryIR } from "./query.js";

export interface DatastoreQueryResult<T extends RecordValue = RecordValue> {
  readonly records: readonly T[];
  readonly plan: ExecutionPlan;
}

export interface DatastoreMutationResult<T extends RecordValue = RecordValue> {
  readonly affected: number;
  readonly records?: readonly T[];
  readonly ids?: readonly string[];
  readonly revisions?: readonly string[];
  readonly plan: ExecutionPlan;
}

/**
 * Backend-neutral execution boundary consumed by application adapters.
 *
 * Consumers depend on this contract rather than importing a concrete backend.
 * Backends implement the same contract while retaining their own transport,
 * schema introspection, and datastore-specific capability handling.
 */
export interface DatastoreRuntime {
  query(query: QueryIR): Promise<DatastoreQueryResult>;
  mutate(mutation: MutationIR): Promise<DatastoreMutationResult>;
}
