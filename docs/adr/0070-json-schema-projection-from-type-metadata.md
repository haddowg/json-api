# Project JSON Schema 2020-12 from JSON:API type metadata

OpenAPI 3.1 Schema Objects *are* JSON Schema 2020-12, and our constraint
vocabulary maps to that dialect near 1:1, so we project the OpenAPI schema for a
type directly from its field + constraint metadata rather than down-projecting to
an older draft. We added an immutable `OpenApi\Schema` value object (a typed
2020-12 node with `toArray()` / `toJson()`) and a pure `OpenApi\SchemaProjector`
that maps a `FieldInterface` — and a type's attributes and resource-object schemas
— into it.

The projector is a **sibling** of the existing `Validation\SchemaCompiler`, not a
replacement: the compiler emits a body-validation *tightening fragment* (for opis),
while the projector emits a **standalone, OpenAPI-shaped** schema (with
`description`/`example`, full nullable handling, the complete resource object).
They share the same constraint→keyword mapping table by convention; the compiler is
left untouched so request/response body validation keeps working unchanged.

## Consequences

Constraints with no faithful JSON Schema 2020-12 expression — `When` with an opaque
condition, `CompareField` (no cross-property comparison in the dialect), and **any**
`After`/`Before`/`Between` date bound (**fixed or closure**: 2020-12 has no keyword
that bounds a `date-time` *string* — `minimum`/`maximum` are numeric-only and
`formatMinimum`/`formatMaximum` are non-standard and silently ignored) — **degrade
to a human-readable note appended to the schema `description`**, never a wrong or
guessed keyword. `Decimal` projects to `{type: number}` (core serializes it as a PHP
`float`, so the wire value is a JSON number — the string/`format: decimal` form
would misdescribe every real response). The `Schema` node serializes empty
sub-schemas (e.g. an `ArrayList`'s default `items`) as JSON **objects** (`toJson()`
→ `stdClass`), since an empty PHP array would otherwise encode as `[]` and be
rejected as an invalid schema.
