# Vendored OpenAPI 3.1 meta-schema

These documents let the test suite validate emitted OpenAPI documents against the
**official OAS 3.1 meta-schema** (`opis/json-schema` ships the JSON Schema 2020-12
parser but no meta-schema JSON, so the schemas are vendored and registered by their
canonical `$id`). See `OpenApiMetaValidationTest`.

## Files

| File | Source | `$id` |
|------|--------|-------|
| `schema.upstream.json` | verbatim upstream | `https://spec.openapis.org/oas/3.1/schema/2022-10-07` |
| `schema.json` | upstream **with two local adaptations** (below) | same `$id` |
| `dialect.json` | verbatim upstream | `https://spec.openapis.org/oas/3.1/dialect/base` |
| `meta/base.json` | verbatim upstream | `https://spec.openapis.org/oas/3.1/meta/base` |

Fetched from `https://spec.openapis.org/oas/3.1/schema/2022-10-07` (the published
3.1 schema release), plus the `dialect/base` and `meta/base` documents it declares.
The OAS schema's `$schema` is JSON Schema 2020-12, so the already-vendored 2020-12
meta-schema (`../meta-schema/`) must also be registered for validation.

## Local adaptations in `schema.json`

Both work around `opis/json-schema` 2.6 limitations — not spec defects. `schema.json`
is what the tests register; `schema.upstream.json` is the pristine copy for diffing.

1. **`{"$dynamicRef": "#meta"}` → `{"$ref": "#/$defs/schema"}`** (4 Schema Object
   slots: `components.schemas.*`, and the `schema` member of parameter / media-type /
   header). The only `$dynamicAnchor: "meta"` in the document is the permissive
   `$defs/schema` placeholder, so dynamic and static resolution are equivalent; opis
   2.6 otherwise over-resolves `#meta` to the document root, which would force every
   component schema to be a whole OpenAPI document.

2. **`unevaluatedProperties: false` → `true` on `$defs/parameter` and `$defs/header`.**
   The Parameter and Header Objects (the parameter-shaped family) close themselves
   with `unevaluatedProperties: false` over a deeply nested
   `dependentSchemas`/`allOf`/`if/then/else` style chain whose conditional-branch
   property annotations opis 2.6 fails to propagate, spuriously rejecting even
   spec-compliant parameters/headers. Their `properties`/`required` (incl. the
   `path` → `required: true` conditional) remain enforced. Other conditional defs
   (`securityScheme`, `mediaType`) are left untouched — their `allOf` conditionals
   do not trip opis.

   **This relaxation is benign for generated documents.** The projector emits a
   parameter/header *only* through the typed `Parameter` / `Header` value objects,
   whose serialization is a **closed shape** — they can emit only the members the OAS
   Parameter/Header Objects define and no other, so an unknown member (the very thing
   the relaxed `unevaluatedProperties` would no longer catch) cannot be produced.
   `ParameterClosedShapeTest` pins that closed shape, so a VO-model regression is
   caught there independently of the relaxed meta-schema.

To refresh: re-fetch upstream into `schema.upstream.json`, re-apply the two edits to
produce `schema.json`, and re-run `OpenApiMetaValidationTest`.
