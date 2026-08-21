import type { Diagnostic } from "@hibari/core";

export type PrismaDiagnosticCode =
  | "HIB-PRISMA-SQL-001"
  | "HIB-PRISMA-JOIN-001"
  | "HIB-PRISMA-AGG-001"
  | "HIB-PRISMA-TXN-001"
  | "HIB-PRISMA-SCHEMA-001"
  | "HIB-PRISMA-RETURNING-001";

export const prismaDiagnosticCodes: Readonly<{
  unsupportedSql: PrismaDiagnosticCode;
  unsupportedJoin: PrismaDiagnosticCode;
  unsupportedAggregate: PrismaDiagnosticCode;
  unsupportedTransaction: PrismaDiagnosticCode;
  unsupportedSchemaSql: PrismaDiagnosticCode;
  missingReturningRecords: PrismaDiagnosticCode;
}> = {
  unsupportedSql: "HIB-PRISMA-SQL-001",
  unsupportedJoin: "HIB-PRISMA-JOIN-001",
  unsupportedAggregate: "HIB-PRISMA-AGG-001",
  unsupportedTransaction: "HIB-PRISMA-TXN-001",
  unsupportedSchemaSql: "HIB-PRISMA-SCHEMA-001",
  missingReturningRecords: "HIB-PRISMA-RETURNING-001"
};

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
