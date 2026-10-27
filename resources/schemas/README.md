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
provider relies on). **Do not** hand-edit it. The provider applies the
transformations it needs at load time (see below).

### Note on the document-root `unevaluatedProperties`

The file is kept byte-faithful to upstream, **including** its document-root
`"unevaluatedProperties": false`. `VendoredSchemaProvider` strips that single
root keyword at load time and the `DocumentValidator` re-applies it on the
synthetic `allOf` composite instead. This is what lets a profile-contributed
schema fragment **extend** the set of allowed top-level members (a fragment's
top-level `properties` are seen by the composite's `unevaluatedProperties`,
whereas they would be invisible to the base's own root keyword). Nested
`unevaluatedProperties` in the base are left untouched, except on
`resourceLinks` (see below).

### Note on `definitions.linkUrl`'s `format`

Upstream types a link URL as `"format": "uri"`, which only an absolute URI
satisfies. JSON:API 1.1 allows a link to be any URI-reference
([RFC 3986 §4.1](https://www.rfc-editor.org/rfc/rfc3986#section-4.1)), so a
host-relative `/api/v5/albums?page[number]=2` is a valid link. `VendoredSchemaProvider`
rewrites that one keyword to `"format": "uri-reference"` at load time; the
definition's `pattern` is kept as-is. The request schema reaches links only through
this definition, so it needs no change of its own.
`VendoredSchemaProviderTest::noLoadedSchemaDemandsAnAbsoluteUri` fails if a refresh
brings in another `"format": "uri"`, which would then need the same treatment.

### Note on `definitions.resourceLinks`

Upstream closes a resource's links object with `"unevaluatedProperties": false`,
so only `self` and `@`-members pass. JSON:API 1.1 says that object MAY contain a
`self` link and forbids nothing else, and the library renders other members by
design: declared links and action links. `VendoredSchemaProvider` replaces that
one keyword with `"unevaluatedProperties": {"$ref": "#/definitions/link"}` at load
time, so any other member must still be a valid link.

### Note on `definitions.attributes`' member values

JSON:API 1.1 says any object that is, or sits inside, an attribute value MUST NOT
contain a `relationships` or `links` member. Upstream lets an attribute hold any
JSON value, so it never checks this. `VendoredSchemaProvider` adds a recursive
`definitions.attributeValue` at load time. It sets `links` and `relationships` to
`false` on every object it meets and applies itself to each member and each array
item below. `definitions.attributes` then gets a `patternProperties` entry, keyed on
the `memberName` pattern, that points each attribute's value at it. A top-level
attribute called `links` stays allowed, and the `type`/`id` rule and `@`-members are
untouched. The request schema reaches attributes only through this definition, so
it needs no change of its own. If a refresh adds an upstream `attributeValue` or a
`patternProperties` on `attributes`, revisit this transformation.

## `jsonapi-1.1-request.json` — authored

Authored here (not upstream) from the base schema's already-present
request-oriented definitions (`resourceIdentificationNew`,
`relationshipsFromRequest`, `relationshipFromRequest`). If the base file is
refreshed and those definition names change, update the cross-`$ref`s here to
match.
