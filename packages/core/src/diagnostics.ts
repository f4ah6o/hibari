export type DiagnosticSeverity = "info" | "warning" | "error";

export const diagnosticCodes = {
  unsupportedCapability: "HIB-CAP-001",
  emulatedCapability: "HIB-CAP-002",
  capabilityLimitExceeded: "HIB-LIMIT-001",
  capabilityLimitApproaching: "HIB-COST-001",
  highRequestCost: "HIB-COST-002",
  schemaModelMissing: "HIB-SCHEMA-001",
  schemaFieldMissing: "HIB-SCHEMA-002",
  schemaFieldIncompatible: "HIB-SCHEMA-003",
  schemaIdentifierMismatch: "HIB-SCHEMA-004",
  schemaConcurrencyMismatch: "HIB-SCHEMA-005"
} as const;

export type CoreDiagnosticCode = (typeof diagnosticCodes)[keyof typeof diagnosticCodes];

export interface SourceLocation {
  readonly file: string;
  readonly line?: number;
  readonly column?: number;
}

export interface Diagnostic {
  readonly code: CoreDiagnosticCode | (string & {});
  readonly severity: DiagnosticSeverity;
  readonly operation: string;
  readonly target: string;
  readonly reason: string;
  readonly message: string;
  readonly capability?: string;
  readonly hint?: string;
  readonly path?: string;
  readonly source?: SourceLocation;
  readonly details?: Readonly<Record<string, unknown>>;
}
