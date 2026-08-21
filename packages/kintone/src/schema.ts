import type {
  EmbeddedCollectionField,
  ModelSchema,
  RelationHint,
  ScalarField,
  ScalarType
} from "@hibari/core";
import type {
  KintoneFieldProperty,
  KintoneFormFieldsResponse,
  KintoneModelBinding,
  KintoneSchemaResult
} from "./types.js";

const immutableTypes = new Set([
  "CALC",
  "CATEGORY",
  "CREATED_TIME",
  "CREATOR",
  "MODIFIER",
  "RECORD_NUMBER",
  "REFERENCE_TABLE",
  "STATUS",
  "STATUS_ASSIGNEE",
  "UPDATED_TIME"
]);

function scalarType(type: string): ScalarType {
  switch (type) {
    case "NUMBER":
    case "CALC":
      return "number";
    case "DATE":
      return "date";
    case "DATETIME":
    case "CREATED_TIME":
    case "UPDATED_TIME":
      return "datetime";
    case "CHECK_BOX":
    case "MULTI_SELECT":
    case "USER_SELECT":
    case "ORGANIZATION_SELECT":
    case "GROUP_SELECT":
    case "FILE":
    case "CATEGORY":
    case "STATUS_ASSIGNEE":
      return "json";
    default:
      return "string";
  }
}

function applicationName(binding: KintoneModelBinding, code: string): string {
  for (const [name, mappedCode] of Object.entries(binding.fieldCodes ?? {})) {
    if (mappedCode === code) {
      return name;
    }
  }
  return code;
}

function extension(property: KintoneFieldProperty, binding: KintoneModelBinding) {
  return {
    kintone: {
      app: binding.app,
      code: property.code,
      type: property.type,
      ...(property.lookup === undefined ? {} : { lookup: property.lookup }),
      ...(property.referenceTable === undefined
        ? {}
        : { referenceTable: property.referenceTable })
    }
  } as const;
}

function toScalarField(
  property: KintoneFieldProperty,
  binding: KintoneModelBinding
): ScalarField {
  return {
    kind: "scalar",
    name: applicationName(binding, property.code),
    type: scalarType(property.type),
    nullable: property.required !== true,
    mutable: !immutableTypes.has(property.type),
    extensions: extension(property, binding)
  };
}

function toEmbeddedField(
  property: KintoneFieldProperty,
  binding: KintoneModelBinding
): EmbeddedCollectionField {
  const fields = Object.values(property.fields ?? {})
    .filter((field) => field.type !== "GROUP" && field.type !== "SUBTABLE")
    .map((field) => toScalarField(field, binding));

  return {
    kind: "embeddedCollection",
    name: applicationName(binding, property.code),
    fields,
    mutable: true,
    extensions: extension(property, binding)
  };
}

function relationHint(
  property: KintoneFieldProperty,
  binding: KintoneModelBinding
): RelationHint | undefined {
  const name = applicationName(binding, property.code);
  if (property.lookup?.relatedApp?.app !== undefined) {
    return {
      name: `${name}:lookup`,
      fields: [name],
      targetModel: property.lookup.relatedApp.code || `kintone-app-${property.lookup.relatedApp.app}`,
      ...(property.lookup.relatedKeyField === undefined
        ? {}
        : { targetFields: [property.lookup.relatedKeyField] }),
      cardinality: "one",
      extensions: extension(property, binding)
    };
  }

  if (property.referenceTable?.relatedApp?.app !== undefined) {
    return {
      name: `${name}:relatedRecords`,
      fields:
        property.referenceTable.condition?.field === undefined
          ? [name]
          : [property.referenceTable.condition.field],
      targetModel:
        property.referenceTable.relatedApp.code ||
        `kintone-app-${property.referenceTable.relatedApp.app}`,
      ...(property.referenceTable.condition?.relatedField === undefined
        ? {}
        : { targetFields: [property.referenceTable.condition.relatedField] }),
      cardinality: "many",
      extensions: extension(property, binding)
    };
  }

  return undefined;
}

export function schemaFromFormFields(
  response: KintoneFormFieldsResponse,
  binding: KintoneModelBinding
): KintoneSchemaResult {
  const properties = Object.values(response.properties);
  const idName = applicationName(binding, "$id");
  const revisionName = applicationName(binding, "$revision");
  const fields = [
    {
      kind: "scalar" as const,
      name: idName,
      type: "integer" as const,
      nullable: false,
      mutable: false,
      extensions: { kintone: { app: binding.app, code: "$id", type: "__ID__" } }
    },
    {
      kind: "scalar" as const,
      name: revisionName,
      type: "integer" as const,
      nullable: false,
      mutable: false,
      extensions: {
        kintone: { app: binding.app, code: "$revision", type: "__REVISION__" }
      }
    },
    ...properties
      .filter((property) => property.type !== "GROUP" && property.type !== "REFERENCE_TABLE")
      .map((property) =>
        property.type === "SUBTABLE"
          ? toEmbeddedField(property, binding)
          : toScalarField(property, binding)
      )
  ];

  const uniqueConstraints = properties
    .filter((property) => property.unique === true)
    .map((property) => ({ fields: [applicationName(binding, property.code)] }));

  const relationHints = properties
    .map((property) => relationHint(property, binding))
    .filter((hint): hint is RelationHint => hint !== undefined);

  const schema: ModelSchema = {
    name: binding.model,
    fields,
    identifier: [idName],
    uniqueConstraints,
    concurrencyToken: { field: revisionName, kind: "revision" },
    relationHints,
    extensions: {
      kintone: {
        app: binding.app,
        settingsRevision: response.revision
      }
    }
  };

  return { schema, revision: response.revision };
}
