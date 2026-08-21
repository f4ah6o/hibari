import type { FilterOperator } from "./query.js";
import type { MutationOperation } from "./mutation.js";

export type CapabilitySupport = "native" | "emulated" | "unsupported";

export interface BoundedCapability {
  readonly support: CapabilitySupport;
  readonly maximum?: number;
  readonly warnAt?: number;
}

export interface QueryCapabilities {
  readonly projection: CapabilitySupport;
  readonly filters: Readonly<Record<FilterOperator, CapabilitySupport>>;
  readonly ordering: CapabilitySupport;
  readonly cursor: CapabilitySupport;
  readonly offset: BoundedCapability;
  readonly join: CapabilitySupport;
  readonly aggregate: CapabilitySupport;
}

export interface MutationCapabilities {
  readonly operations: Readonly<Record<MutationOperation, CapabilitySupport>>;
  readonly optimisticConcurrency: CapabilitySupport;
}

export interface TransactionCapabilities {
  readonly atomicBatch: CapabilitySupport;
  readonly interactive: CapabilitySupport;
}

export interface CapabilityLimits {
  readonly pageSize?: number;
  readonly batchSize?: number;
  readonly requestConcurrency?: number;
  readonly requestBudget?: number;
  readonly requestWarningAt?: number;
}

export interface CapabilityManifest {
  readonly backend: string;
  readonly query: QueryCapabilities;
  readonly mutation: MutationCapabilities;
  readonly transaction: TransactionCapabilities;
  readonly limits?: CapabilityLimits;
  readonly extensions?: Readonly<Record<string, unknown>>;
}
