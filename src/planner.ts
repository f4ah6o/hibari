import type {
  BoundedCapability,
  CapabilityManifest,
  CapabilitySupport
} from "./capabilities.js";
import { diagnosticCodes, type Diagnostic } from "./diagnostics.js";
import type { MutationIR } from "./mutation.js";
import { collectFilterOperators, type QueryIR } from "./query.js";
import type {
  CapabilityAssessment,
  Classification,
  ExecutionPlan
} from "./plan.js";

const classificationWeight: Readonly<Record<Classification, number>> = {
  native: 0,
  emulated: 1,
  expensive: 2,
  unsupported: 3
};

function combineClassification(
  current: Classification,
  next: Classification
): Classification {
  return classificationWeight[next] > classificationWeight[current] ? next : current;
}

function supportToClassification(support: CapabilitySupport): Classification {
  return support;
}

interface PlannerState {
  classification: Classification;
  assessments: CapabilityAssessment[];
  diagnostics: Diagnostic[];
  clientSideEvaluation: boolean;
}

function createPlannerState(): PlannerState {
  return {
    classification: "native",
    assessments: [],
    diagnostics: [],
    clientSideEvaluation: false
  };
}

function assessSupport(
  state: PlannerState,
  capability: string,
  support: CapabilitySupport,
  operation: string,
  target: string,
  path?: string,
  clientSideEvaluation = false
): void {
  const classification = supportToClassification(support);
  state.classification = combineClassification(state.classification, classification);
  state.assessments.push(
    path === undefined
      ? { capability, classification }
      : { capability, classification, path }
  );

  if (support === "unsupported") {
    state.diagnostics.push({
      code: diagnosticCodes.unsupportedCapability,
      severity: "error",
      operation,
      target,
      reason: "backend capability is unsupported",
      message: `Capability '${capability}' is not supported by this backend.`,
      capability,
      hint: "Change the operation to use portable capabilities or choose a backend that supports this capability.",
      ...(path === undefined ? {} : { path })
    });
    return;
  }

  if (support === "emulated") {
    state.clientSideEvaluation ||= clientSideEvaluation;
    state.diagnostics.push({
      code: diagnosticCodes.emulatedCapability,
      severity: "info",
      operation,
      target,
      reason: "backend capability requires Hibari emulation",
      message: `Capability '${capability}' will be emulated by Hibari.`,
      capability,
      ...(path === undefined ? {} : { path })
    });
  }
}

function assessBoundedNumber(
  state: PlannerState,
  capability: string,
  value: number,
  config: BoundedCapability,
  operation: string,
  target: string,
  path: string
): void {
  assessSupport(state, capability, config.support, operation, target, path);

  if (config.support === "unsupported") {
    return;
  }

  if (config.maximum !== undefined && value > config.maximum) {
    state.classification = "unsupported";
    state.assessments[state.assessments.length - 1] = {
      capability,
      classification: "unsupported",
      path
    };
    state.diagnostics.push({
      code: diagnosticCodes.capabilityLimitExceeded,
      severity: "error",
      operation,
      target,
      reason: "backend capability limit exceeded",
      message: `Capability '${capability}' value ${value} exceeds maximum ${config.maximum}.`,
      capability,
      hint: `Use a value at or below ${config.maximum} or use a cursor/seek strategy when available.`,
      path,
      details: { value, maximum: config.maximum }
    });
    return;
  }

  if (config.warnAt !== undefined && value >= config.warnAt) {
    state.classification = combineClassification(state.classification, "expensive");
    state.assessments[state.assessments.length - 1] = {
      capability,
      classification: "expensive",
      path
    };
    state.diagnostics.push({
      code: diagnosticCodes.capabilityLimitApproaching,
      severity: "warning",
      operation,
      target,
      reason: "operation approaches a backend capability limit",
      message: `Capability '${capability}' value ${value} is at or above warning threshold ${config.warnAt}.`,
      capability,
      hint: "Prefer a cursor/seek strategy or reduce the requested range when possible.",
      path,
      details: { value, warnAt: config.warnAt }
    });
  }
}

function estimateQueryRequests(query: QueryIR, manifest: CapabilityManifest): number | undefined {
  const pageSize = manifest.limits?.pageSize;
  if (pageSize === undefined || query.limit === undefined || query.limit <= 0) {
    return undefined;
  }

  return Math.max(1, Math.ceil(query.limit / pageSize));
}

function estimateMutationRequests(mutation: MutationIR, manifest: CapabilityManifest): number | undefined {
  const batchSize = manifest.limits?.batchSize;
  if (batchSize === undefined || mutation.operation !== "insertMany") {
    return undefined;
  }

  if (mutation.records.length === 0) {
    return 0;
  }

  return Math.ceil(mutation.records.length / batchSize);
}

function assessRequestCost(
  state: PlannerState,
  estimatedRequests: number | undefined,
  manifest: CapabilityManifest,
  operation: string,
  target: string
): void {
  if (estimatedRequests === undefined) {
    return;
  }

  const warningAt = manifest.limits?.requestWarningAt;
  const budget = manifest.limits?.requestBudget;
  const threshold = warningAt ?? budget;

  if (threshold !== undefined && estimatedRequests >= threshold) {
    state.classification = combineClassification(state.classification, "expensive");
    state.diagnostics.push({
      code: diagnosticCodes.highRequestCost,
      severity: "warning",
      operation,
      target,
      reason: "execution plan has high backend request cost",
      message: `Execution is estimated to require ${estimatedRequests} backend requests.`,
      capability: "limits.requestBudget",
      hint: "Reduce the requested range or batch size pressure when possible.",
      details: {
        estimatedRequests,
        ...(warningAt === undefined ? {} : { warningAt }),
        ...(budget === undefined ? {} : { requestBudget: budget })
      }
    });
  }
}

function finalizePlan(
  operation: "query" | "mutation",
  model: string,
  state: PlannerState,
  estimatedRequests?: number
): ExecutionPlan {
  const base = {
    operation,
    model,
    classification: state.classification,
    assessments: state.assessments,
    clientSideEvaluation: state.clientSideEvaluation,
    diagnostics: state.diagnostics
  } as const;

  return estimatedRequests === undefined ? base : { ...base, estimatedRequests };
}

export function planQuery(query: QueryIR, manifest: CapabilityManifest): ExecutionPlan {
  const state = createPlannerState();

  if (query.projection !== undefined) {
    assessSupport(state, "query.projection", manifest.query.projection, "query", query.model, "projection", true);
  }

  for (const operator of new Set(collectFilterOperators(query.filter))) {
    assessSupport(
      state,
      `query.filters.${operator}`,
      manifest.query.filters[operator],
      "query",
      query.model,
      "filter",
      true
    );
  }

  if (query.ordering !== undefined && query.ordering.length > 0) {
    assessSupport(state, "query.ordering", manifest.query.ordering, "query", query.model, "ordering", true);
  }

  if (query.cursor !== undefined) {
    assessSupport(state, "query.cursor", manifest.query.cursor, "query", query.model, "cursor");
  }

  if (query.offset !== undefined && query.offset > 0) {
    assessBoundedNumber(state, "query.offset", query.offset, manifest.query.offset, "query", query.model, "offset");
  }

  const estimatedRequests = estimateQueryRequests(query, manifest);
  assessRequestCost(state, estimatedRequests, manifest, "query", query.model);

  return finalizePlan("query", query.model, state, estimatedRequests);
}

export function planMutation(
  mutation: MutationIR,
  manifest: CapabilityManifest
): ExecutionPlan {
  const state = createPlannerState();

  assessSupport(
    state,
    `mutation.operations.${mutation.operation}`,
    manifest.mutation.operations[mutation.operation],
    "mutation",
    mutation.model,
    "operation"
  );

  if (
    mutation.operation === "update" ||
    mutation.operation === "updateMany" ||
    mutation.operation === "delete" ||
    mutation.operation === "upsert"
  ) {
    for (const operator of new Set(collectFilterOperators(mutation.where))) {
      assessSupport(
        state,
        `query.filters.${operator}`,
        manifest.query.filters[operator],
        "mutation",
        mutation.model,
        "where",
        true
      );
    }
  }

  if (
    (mutation.operation === "update" ||
      mutation.operation === "delete" ||
      mutation.operation === "upsert") &&
    mutation.concurrency !== undefined
  ) {
    assessSupport(
      state,
      "mutation.optimisticConcurrency",
      manifest.mutation.optimisticConcurrency,
      "mutation",
      mutation.model,
      "concurrency"
    );
  }

  const estimatedRequests = estimateMutationRequests(mutation, manifest);
  assessRequestCost(state, estimatedRequests, manifest, "mutation", mutation.model);

  return finalizePlan("mutation", mutation.model, state, estimatedRequests);
}

export function plan(
  operation: QueryIR | MutationIR,
  manifest: CapabilityManifest
): ExecutionPlan {
  return operation.kind === "query"
    ? planQuery(operation, manifest)
    : planMutation(operation, manifest);
}
