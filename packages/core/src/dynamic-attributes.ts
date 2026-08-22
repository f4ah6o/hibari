import type {
  CapabilityManifest,
  CapabilitySupport,
  DynamicAttributeCapabilities
} from "./capabilities.js";
import { diagnosticCodes, type Diagnostic } from "./diagnostics.js";
import type { MutationIR } from "./mutation.js";
import type { CapabilityAssessment, Classification, ExecutionPlan } from "./plan.js";
import { planMutation, planQuery } from "./planner.js";
import type { FilterExpression, QueryIR, ScalarValue } from "./query.js";

export interface DynamicAttributeBinding {
  readonly model: string;
  readonly idField: string;
  readonly ownerField: string;
  readonly keyField: string;
  readonly valueField: string;
}

interface DynamicAttributeOperationBase {
  readonly kind: "dynamicAttribute";
  readonly binding: DynamicAttributeBinding;
  readonly ownerId: ScalarValue;
  readonly key: string;
}

export interface DynamicAttributeLookupOperation extends DynamicAttributeOperationBase {
  readonly operation: "lookup";
  readonly value?: ScalarValue;
  readonly limit?: number;
}

export interface DynamicAttributeAddOperation extends DynamicAttributeOperationBase {
  readonly operation: "add";
  readonly value: ScalarValue;
  readonly unique?: boolean;
}

export interface DynamicAttributeUpdateOperation extends DynamicAttributeOperationBase {
  readonly operation: "update";
  readonly value: ScalarValue;
  readonly previousValue?: ScalarValue;
}

export interface DynamicAttributeDeleteOperation extends DynamicAttributeOperationBase {
  readonly operation: "delete";
  readonly value?: ScalarValue;
}

export type DynamicAttributeOperationIR =
  | DynamicAttributeLookupOperation
  | DynamicAttributeAddOperation
  | DynamicAttributeUpdateOperation
  | DynamicAttributeDeleteOperation;

export type LoweredDynamicAttributeStep = QueryIR | MutationIR;

const classificationWeight: Readonly<Record<Classification, number>> = {
  native: 0,
  emulated: 1,
  expensive: 2,
  unsupported: 3
};

function combineClassification(current: Classification, next: Classification): Classification {
  return classificationWeight[next] > classificationWeight[current] ? next : current;
}

function and(expressions: readonly FilterExpression[]): FilterExpression {
  return expressions.length === 1
    ? expressions[0]!
    : { op: "and", expressions };
}

function selector(
  operation: DynamicAttributeOperationIR,
  includeValue: boolean
): FilterExpression {
  const expressions: FilterExpression[] = [
    { op: "eq", field: operation.binding.ownerField, value: operation.ownerId },
    { op: "eq", field: operation.binding.keyField, value: operation.key }
  ];

  if (includeValue) {
    const value =
      operation.operation === "update"
        ? operation.previousValue
        : operation.operation === "lookup" || operation.operation === "delete"
          ? operation.value
          : undefined;
    if (value !== undefined) {
      expressions.push({ op: "eq", field: operation.binding.valueField, value });
    }
  }

  return and(expressions);
}

export function lowerDynamicAttributeOperation(
  operation: DynamicAttributeOperationIR
): readonly LoweredDynamicAttributeStep[] {
  const { binding } = operation;

  switch (operation.operation) {
    case "lookup": {
      const query: QueryIR = {
        kind: "query",
        model: binding.model,
        projection: [binding.idField, binding.ownerField, binding.keyField, binding.valueField],
        filter: selector(operation, true),
        ...(operation.limit === undefined ? {} : { limit: operation.limit })
      };
      return [query];
    }
    case "add": {
      const insert: MutationIR = {
        kind: "mutation",
        operation: "insert",
        model: binding.model,
        record: {
          [binding.ownerField]: operation.ownerId,
          [binding.keyField]: operation.key,
          [binding.valueField]: operation.value
        }
      };
      if (!operation.unique) {
        return [insert];
      }
      const preflight: QueryIR = {
        kind: "query",
        model: binding.model,
        projection: [binding.idField],
        filter: selector(operation, false),
        limit: 1
      };
      return [preflight, insert];
    }
    case "update":
      return [
        {
          kind: "mutation",
          operation: "updateMany",
          model: binding.model,
          where: selector(operation, true),
          changes: { [binding.valueField]: operation.value }
        }
      ];
    case "delete":
      return [
        {
          kind: "mutation",
          operation: "delete",
          model: binding.model,
          where: selector(operation, true)
        }
      ];
  }
}

function capabilityFor(
  operation: DynamicAttributeOperationIR,
  capabilities: DynamicAttributeCapabilities | undefined
): readonly [string, CapabilitySupport][] {
  if (capabilities === undefined) {
    return [["dynamicAttributes", "unsupported"]];
  }

  switch (operation.operation) {
    case "lookup":
      return operation.value === undefined
        ? [["dynamicAttributes.ownerKeyLookup", capabilities.ownerKeyLookup]]
        : [["dynamicAttributes.ownerKeyValueLookup", capabilities.ownerKeyValueLookup]];
    case "add":
      return operation.unique
        ? [
            ["dynamicAttributes.multiValue", capabilities.multiValue],
            ["dynamicAttributes.uniqueAdd", capabilities.uniqueAdd]
          ]
        : [["dynamicAttributes.multiValue", capabilities.multiValue]];
    case "update":
      return operation.previousValue === undefined
        ? [["dynamicAttributes.ownerKeyLookup", capabilities.ownerKeyLookup]]
        : [["dynamicAttributes.ownerKeyValueLookup", capabilities.ownerKeyValueLookup]];
    case "delete":
      return operation.value === undefined
        ? [["dynamicAttributes.ownerKeyLookup", capabilities.ownerKeyLookup]]
        : [["dynamicAttributes.ownerKeyValueLookup", capabilities.ownerKeyValueLookup]];
  }
}

function supportDiagnostic(
  capability: string,
  support: CapabilitySupport,
  operation: DynamicAttributeOperationIR
): Diagnostic | undefined {
  if (support === "native") {
    return undefined;
  }
  if (support === "emulated") {
    return {
      code: diagnosticCodes.emulatedCapability,
      severity: "info",
      operation: "dynamicAttribute",
      target: operation.binding.model,
      reason: "backend capability requires Hibari emulation",
      message: `Capability '${capability}' will be emulated by Hibari.`,
      capability
    };
  }
  return {
    code: diagnosticCodes.unsupportedCapability,
    severity: "error",
    operation: "dynamicAttribute",
    target: operation.binding.model,
    reason: "backend capability is unsupported",
    message: `Capability '${capability}' is not supported by this backend.`,
    capability,
    hint: "Use a backend/profile that supports the required dynamic-attribute semantics."
  };
}

export function planDynamicAttributeOperation(
  operation: DynamicAttributeOperationIR,
  manifest: CapabilityManifest
): ExecutionPlan {
  let classification: Classification = "native";
  let clientSideEvaluation = false;
  let estimatedRequests = 0;
  let hasEstimate = false;
  const assessments: CapabilityAssessment[] = [];
  const diagnostics: Diagnostic[] = [];

  for (const [capability, support] of capabilityFor(operation, manifest.dynamicAttributes)) {
    classification = combineClassification(classification, support);
    assessments.push({ capability, classification: support });
    const diagnostic = supportDiagnostic(capability, support, operation);
    if (diagnostic !== undefined) diagnostics.push(diagnostic);
  }

  for (const step of lowerDynamicAttributeOperation(operation)) {
    const stepPlan = step.kind === "query" ? planQuery(step, manifest) : planMutation(step, manifest);
    classification = combineClassification(classification, stepPlan.classification);
    clientSideEvaluation ||= stepPlan.clientSideEvaluation;
    assessments.push(...stepPlan.assessments);
    diagnostics.push(...stepPlan.diagnostics);
    if (stepPlan.estimatedRequests !== undefined) {
      estimatedRequests += stepPlan.estimatedRequests;
      hasEstimate = true;
    }
  }

  const base: ExecutionPlan = {
    operation: operation.operation === "lookup" ? "query" : "mutation",
    model: operation.binding.model,
    classification,
    assessments,
    clientSideEvaluation,
    diagnostics
  };

  return hasEstimate ? { ...base, estimatedRequests } : base;
}
