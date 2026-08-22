import type {
  CapabilityManifest,
  CapabilitySupport,
  RelationEdgeCapabilities
} from "./capabilities.js";
import { diagnosticCodes, type Diagnostic } from "./diagnostics.js";
import type { MutationIR } from "./mutation.js";
import type { CapabilityAssessment, Classification, ExecutionPlan } from "./plan.js";
import { planMutation, planQuery } from "./planner.js";
import type { FilterExpression, QueryIR, ScalarValue } from "./query.js";

export interface RelationEdgeBinding {
  readonly model: string;
  readonly idField: string;
  readonly leftField: string;
  readonly rightField: string;
  readonly contextField?: string;
  readonly orderField?: string;
}

interface RelationEdgeBase {
  readonly kind: "relationEdge";
  readonly binding: RelationEdgeBinding;
  readonly leftId: ScalarValue;
  readonly context?: ScalarValue;
}

export interface RelationEdgeLookupOperation extends RelationEdgeBase {
  readonly operation: "lookup";
  readonly rightId?: ScalarValue;
  readonly limit?: number;
}

export interface RelationEdgeAttachOperation extends RelationEdgeBase {
  readonly operation: "attach";
  readonly rightId: ScalarValue;
  readonly order?: number;
  readonly unique?: boolean;
}

export interface RelationEdgeDetachOperation extends RelationEdgeBase {
  readonly operation: "detach";
  readonly rightIds?: readonly ScalarValue[];
}

export interface RelationEdgeReplaceOperation extends RelationEdgeBase {
  readonly operation: "replace";
  readonly currentRightIds: readonly ScalarValue[];
  readonly nextRightIds: readonly ScalarValue[];
}

export type RelationEdgeOperationIR =
  | RelationEdgeLookupOperation
  | RelationEdgeAttachOperation
  | RelationEdgeDetachOperation
  | RelationEdgeReplaceOperation;

export type LoweredRelationEdgeStep = QueryIR | MutationIR;

const weight: Readonly<Record<Classification, number>> = {
  native: 0,
  emulated: 1,
  expensive: 2,
  unsupported: 3
};

function combine(current: Classification, next: Classification): Classification {
  return weight[next] > weight[current] ? next : current;
}

function and(expressions: readonly FilterExpression[]): FilterExpression {
  return expressions.length === 1 ? expressions[0]! : { op: "and", expressions };
}

function baseFilter(operation: RelationEdgeOperationIR): FilterExpression[] {
  const filters: FilterExpression[] = [
    { op: "eq", field: operation.binding.leftField, value: operation.leftId }
  ];
  if (operation.binding.contextField !== undefined && operation.context !== undefined) {
    filters.push({
      op: "eq",
      field: operation.binding.contextField,
      value: operation.context
    });
  }
  return filters;
}

function pairFilter(operation: RelationEdgeLookupOperation | RelationEdgeAttachOperation): FilterExpression {
  return and([
    ...baseFilter(operation),
    { op: "eq", field: operation.binding.rightField, value: operation.rightId }
  ]);
}

function scalarKey(value: ScalarValue): string {
  return `${typeof value}:${String(value)}`;
}

export function lowerRelationEdgeOperation(
  operation: RelationEdgeOperationIR
): readonly LoweredRelationEdgeStep[] {
  const { binding } = operation;

  if (operation.operation === "lookup") {
    const filters = baseFilter(operation);
    if (operation.rightId !== undefined) {
      filters.push({ op: "eq", field: binding.rightField, value: operation.rightId });
    }
    return [
      {
        kind: "query",
        model: binding.model,
        projection: [binding.idField, binding.leftField, binding.rightField],
        filter: and(filters),
        ...(operation.limit === undefined ? {} : { limit: operation.limit })
      }
    ];
  }

  if (operation.operation === "attach") {
    const record: Record<string, ScalarValue> = {
      [binding.leftField]: operation.leftId,
      [binding.rightField]: operation.rightId
    };
    if (binding.contextField !== undefined && operation.context !== undefined) {
      record[binding.contextField] = operation.context;
    }
    if (binding.orderField !== undefined && operation.order !== undefined) {
      record[binding.orderField] = operation.order;
    }
    const insert: MutationIR = {
      kind: "mutation",
      operation: "insert",
      model: binding.model,
      record
    };
    if (operation.unique === false) return [insert];
    return [
      {
        kind: "query",
        model: binding.model,
        projection: [binding.idField],
        filter: pairFilter(operation),
        limit: 1
      },
      insert
    ];
  }

  if (operation.operation === "detach") {
    const filters = baseFilter(operation);
    if (operation.rightIds !== undefined && operation.rightIds.length > 0) {
      filters.push({ op: "in", field: binding.rightField, values: operation.rightIds });
    }
    return [
      {
        kind: "mutation",
        operation: "delete",
        model: binding.model,
        where: and(filters)
      }
    ];
  }

  const current = new Map(operation.currentRightIds.map((value) => [scalarKey(value), value]));
  const next = new Map(operation.nextRightIds.map((value) => [scalarKey(value), value]));
  const removed = [...current.entries()]
    .filter(([key]) => !next.has(key))
    .map(([, value]) => value);
  const added = [...next.entries()]
    .filter(([key]) => !current.has(key))
    .map(([, value]) => value);

  const steps: LoweredRelationEdgeStep[] = [];
  if (removed.length > 0) {
    steps.push(...lowerRelationEdgeOperation({
      kind: "relationEdge",
      operation: "detach",
      binding,
      leftId: operation.leftId,
      ...(operation.context === undefined ? {} : { context: operation.context }),
      rightIds: removed
    }));
  }
  for (const rightId of added) {
    steps.push(...lowerRelationEdgeOperation({
      kind: "relationEdge",
      operation: "attach",
      binding,
      leftId: operation.leftId,
      ...(operation.context === undefined ? {} : { context: operation.context }),
      rightId,
      unique: true
    }));
  }
  return steps;
}

function semanticCapabilities(
  operation: RelationEdgeOperationIR,
  capabilities: RelationEdgeCapabilities | undefined
): readonly [string, CapabilitySupport][] {
  if (capabilities === undefined) return [["relationEdges", "unsupported"]];
  switch (operation.operation) {
    case "lookup":
      return operation.rightId === undefined
        ? [["relationEdges.leftScopedLookup", capabilities.leftScopedLookup]]
        : [["relationEdges.pairLookup", capabilities.pairLookup]];
    case "attach":
      return [
        ["relationEdges.multiEdge", capabilities.multiEdge],
        ["relationEdges.attach", capabilities.attach],
        ...(operation.unique === false
          ? []
          : [["relationEdges.uniqueAttach", capabilities.uniqueAttach] as const])
      ];
    case "detach":
      return [["relationEdges.detach", capabilities.detach]];
    case "replace":
      return [["relationEdges.replace", capabilities.replace]];
  }
}

function semanticDiagnostic(
  capability: string,
  support: CapabilitySupport,
  operation: RelationEdgeOperationIR
): Diagnostic | undefined {
  if (support === "native") return undefined;
  if (support === "emulated") {
    return {
      code: diagnosticCodes.emulatedCapability,
      severity: "info",
      operation: "relationEdge",
      target: operation.binding.model,
      reason: "backend capability requires Hibari emulation",
      message: `Capability '${capability}' will be emulated by Hibari.`,
      capability
    };
  }
  return {
    code: diagnosticCodes.unsupportedCapability,
    severity: "error",
    operation: "relationEdge",
    target: operation.binding.model,
    reason: "backend capability is unsupported",
    message: `Capability '${capability}' is not supported by this backend.`,
    capability,
    hint: "Use a backend/profile that supports the required relation-edge semantics."
  };
}

export function planRelationEdgeOperation(
  operation: RelationEdgeOperationIR,
  manifest: CapabilityManifest
): ExecutionPlan {
  let classification: Classification = "native";
  let clientSideEvaluation = false;
  let estimatedRequests = 0;
  let hasEstimate = false;
  const assessments: CapabilityAssessment[] = [];
  const diagnostics: Diagnostic[] = [];

  for (const [capability, support] of semanticCapabilities(operation, manifest.relationEdges)) {
    classification = combine(classification, support);
    assessments.push({ capability, classification: support });
    const diagnostic = semanticDiagnostic(capability, support, operation);
    if (diagnostic !== undefined) diagnostics.push(diagnostic);
  }

  for (const step of lowerRelationEdgeOperation(operation)) {
    const plan = step.kind === "query" ? planQuery(step, manifest) : planMutation(step, manifest);
    classification = combine(classification, plan.classification);
    clientSideEvaluation ||= plan.clientSideEvaluation;
    assessments.push(...plan.assessments);
    diagnostics.push(...plan.diagnostics);
    if (plan.estimatedRequests !== undefined) {
      estimatedRequests += plan.estimatedRequests;
      hasEstimate = true;
    }
  }

  const result: ExecutionPlan = {
    operation: operation.operation === "lookup" ? "query" : "mutation",
    model: operation.binding.model,
    classification,
    assessments,
    clientSideEvaluation,
    diagnostics
  };
  return hasEstimate ? { ...result, estimatedRequests } : result;
}
