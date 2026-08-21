export type ScalarType =
  | "string"
  | "integer"
  | "number"
  | "boolean"
  | "date"
  | "datetime"
  | "json"
  | "bytes"
  | "unknown";

export interface ScalarField {
  readonly kind: "scalar";
  readonly name: string;
  readonly type: ScalarType;
  readonly nullable?: boolean;
  readonly mutable?: boolean;
  readonly extensions?: Readonly<Record<string, unknown>>;
}

export interface EmbeddedCollectionField {
  readonly kind: "embeddedCollection";
  readonly name: string;
  readonly fields: readonly ScalarField[];
  readonly mutable?: boolean;
  readonly extensions?: Readonly<Record<string, unknown>>;
}

export type Field = ScalarField | EmbeddedCollectionField;

export interface UniqueConstraint {
  readonly name?: string;
  readonly fields: readonly string[];
}

export interface RelationHint {
  readonly name: string;
  readonly fields: readonly string[];
  readonly targetModel: string;
  readonly targetFields?: readonly string[];
  readonly cardinality?: "one" | "many" | "unknown";
  readonly extensions?: Readonly<Record<string, unknown>>;
}

export interface ConcurrencyToken {
  readonly field: string;
  readonly kind: "revision" | "opaque";
}

export interface ModelSchema {
  readonly name: string;
  readonly fields: readonly Field[];
  readonly identifier: readonly string[];
  readonly uniqueConstraints?: readonly UniqueConstraint[];
  readonly concurrencyToken?: ConcurrencyToken;
  readonly relationHints?: readonly RelationHint[];
  readonly extensions?: Readonly<Record<string, unknown>>;
}

export interface SchemaIR {
  readonly models: readonly ModelSchema[];
  readonly extensions?: Readonly<Record<string, unknown>>;
}
