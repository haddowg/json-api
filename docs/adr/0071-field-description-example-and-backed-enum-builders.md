# Field/relation/filter `description`/`example` builders and backed-enum support

The OpenAPI generator needs authoring metadata the schema DSL could not express, so
we added — pre-1.0, while the API is still cheap to change — `->description(string)`
and `->example(mixed)` to `AbstractField` (inherited by relations) and the
value-filter `HasValueConstraints` trait, plus backed-enum awareness: `->enum(Class)`
and `->in([...cases])` accept backed-enum cases, normalizing each to its backing
scalar so the stored `In` still carries plain scalars (every existing consumer — the
schema compiler, the framework validator bridge, in-memory filters — is unchanged),
while `In` additionally **retains the enum class-string** so the projector can emit
richer enum metadata. A field's schema `type` follows the enum's backing type.

Per-value enum descriptions ride a generic (no-Symfony) opt-in trio under
`Resource\Enum\`: a `#[EnumCaseDescription]` class-constant attribute, a
`DescribedEnum` marker interface, and a `DescribesEnumCases` reflection trait
(`description()` + a static `descriptions()` mapping backing value → description).
The projector emits the enum as `enum` + `x-enum-varnames` (case names, free for any
backed enum) + `x-enum-descriptions` (when the enum is a `DescribedEnum`) **and** a
markdown `value → description` table in the schema `description`, since the free CDN
renderers (Swagger UI, ReDoc CE) display only the `description`; the
`EnumDescriptionMode` (default `both`) selects which surfaces are emitted.

## Consequences

`In` gained a trailing optional `?string $enumClass` constructor parameter — safe
because the package is pre-1.0 and the new parameter is optional and trailing. The
filter description/example are immutable withers backed by a new
`withDescriptionAndExample()` seam each value-carrying filter implements (the
filter→OpenAPI-parameter projection itself lands in a later slice; only **field**
description/example are projected now).
