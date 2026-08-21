import type { Diagnostic } from "./diagnostics.js";

export type Classification = "native" | "emulated" | "expensive" | "unsupported";

export interface CapabilityAssessment {
  readonly capability: string;
  readonly classification: Classification;
  readonly path?: string;
}

export interface ExecutionPlan {
  readonly operation: "query" | "mutation";
  readonly model: string;
  readonly classification: Classification;
  readonly assessments: readonly CapabilityAssessment[];
  readonly estimatedRequests?: number;
  readonly clientSideEvaluation: boolean;
  readonly diagnostics: readonly Diagnostic[];
}

export class HibariPlanningError extends Error {
  readonly plan: ExecutionPlan;

  constructor(plan: ExecutionPlan) {
    const errors = plan.diagnostics.filter((diagnostic) => diagnostic.severity === "error");
    super(errors[0]?.message ?? "The execution plan is unsupported.");
    this.name = "HibariPlanningError";
    this.plan = plan;
  }
}

export function assertExecutable(plan: ExecutionPlan): ExecutionPlan {
  if (plan.classification === "unsupported") {
    throw new HibariPlanningError(plan);
  }

  return plan;
}
