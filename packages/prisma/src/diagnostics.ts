import type { Diagnostic } from "@hibari/core";

export const prismaDiagnosticCodes = {
  unsupportedSql: "HIB-PRISMA-SQL-001",
  unsupportedJoin: "HIB-PRISMA-JOIN-001",
  unsupportedAggregate: "HIB-PRISMA-AGG-001",
  unsupportedTransaction: "HIB-PRISMA-TXN-001",
  unsupportedSchemaSql: "HIB-PRISMA-SCHEMA-001",
  missingReturningRecords: "HIB-PRISMA-RETURNING-001"
} as const;

export type PrismaDiagnosticCode =
  (typeof prismaDiagnosticCodes)[keyof typeof prismaDiagnosticCodes];

export class PrismaCompatibilityError extends Error {
  readonly diagnostics: readonly Diagnostic[];

  constructor(message: string, diagnostics: readonly Diagnostic[]) {
    super(message);
    this.name = "PrismaCompatibilityError";
    this.diagnostics = diagnostics;
  }
}

export function prismaCompatibilityError(
  code: PrismaDiagnosticCode,
  message: string,
  capability: string,
  sql?: string
): PrismaCompatibilityError {
  return new PrismaCompatibilityError(message, [
    {
      code,
      severity: "error",
      operation: "prisma-sql",
      target: "Prisma",
      reason: "Prisma SQL cannot be represented with equivalent portable datastore semantics",
      message,
      capability,
      ...(sql === undefined ? {} : { details: { sql } })
    }
  ]);
}
