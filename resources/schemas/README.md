# JSON:API JSON Schemas

These JSON Schema (draft 2020-12) documents back the optional, dev/CI-time
document validation in `haddowg\JsonApi\Validation\*`. They are **data files**,
not autoloaded code; `Validation\VendoredSchemaProvider` resolves them by path.

## Files

| File | `$id` | Validates |
|---|---|---|
| `jsonapi-1.1.json` | `https://jsonapi.org/schemas/spec/v1.1/draft` | **Responses** (and the base for everything). A resource object requires `type` + `id` and forbids `lid`. |
| `jsonapi-1.1-request.json` | `https://jsonapi.org/schemas/spec/v1.1/request` | **Requests**. A primary-data resource may omit `id` (client-generated) and may carry `lid`. Cross-`$ref`s the base file's request-oriented definitions. |

## `jsonapi-1.1.json` — upstream source & refresh

`jsonapi-1.1.json` is a **verbatim copy** of the JSON:API 1.1 schema published at:

> https://github.com/VGirol/json-api/blob/schema-1.1/_schemas/1.1/schema.json
> (raw: https://raw.githubusercontent.com/VGirol/json-api/schema-1.1/_schemas/1.1/schema.json)

It is already JSON Schema draft 2020-12, so it feeds `opis/json-schema` 2.x
directly (the canonical jsonapi.org schema is draft-04, which opis 2.x cannot
consume — hence this source).

To refresh, re-download that raw URL over the top of `jsonapi-1.1.json` and run
`composer test` (the `Validation\*` tests pin the structural expectations the
provider relies on). **Do not** hand-edit it — the provider applies the one
transformation it needs at load time (see below).

### Note on the document-root `unevaluatedProperties`

The file is kept byte-faithful to upstream, **including** its document-root
`"unevaluatedProperties": false`. `VendoredSchemaProvider` strips that single
root keyword at load time and the `DocumentValidator` re-applies it on the
synthetic `allOf` composite instead. This is what lets a profile-contributed
schema fragment **extend** the set of allowed top-level members (a fragment's
top-level `properties` are seen by the composite's `unevaluatedProperties`,
whereas they would be invisible to the base's own root keyword). Nested
`unevaluatedProperties` in the base are left untouched.

## `jsonapi-1.1-request.json` — authored

Authored here (not upstream) from the base schema's already-present
request-oriented definitions (`resourceIdentificationNew`,
`relationshipsFromRequest`, `relationshipFromRequest`). If the base file is
refreshed and those definition names change, update the cross-`$ref`s here to
match.
