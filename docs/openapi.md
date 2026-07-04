# OpenAPI generation

The library can project a server's worth of JSON:API metadata into an **OpenAPI
3.1** document — every resource type, its CRUD, relationship, related and custom-action
endpoints, their parameters, request bodies and responses, plus the reusable component
schemas they `$ref`. The projection is a **pure, framework-agnostic** part of core:
give it a description of your server (a metadata contract) and it returns an immutable
OpenAPI value-object tree. A framework integration (the Symfony bundle) supplies that
description from its compiled registry, serves the document at an endpoint, and offers a
Swagger/Redoc UI — but the *semantics* (how a JSON:API type becomes an OpenAPI schema,
how an `?include` becomes a parameter) live here, and are fully testable with in-core
fixtures and no framework.

This page is the reference for that projection: the model that builds the document, the
contract you implement to feed it, the field-level authoring surface that shapes the
schemas (`describedAs()` / `example()`), and the JSON:API-specific vendor extensions it
emits (`x-enum-*`, `x-profile`). For *serving* the document, the config, and the UI, see
the Symfony bundle's OpenAPI docs.

## The projection model

The projection is a small pipeline of **pure** classes in
`haddowg\JsonApi\OpenApi`, composed from the leaf up:

- **`Schema`** — an immutable JSON Schema 2020-12 node, the dialect an OpenAPI 3.1
  Schema Object speaks. Built fluently from typed withers (`Schema::ofType('string')
  ->withEnum([…])`, `Schema::ref('#/components/schemas/…')`, `Schema::never()` for the
  boolean `false` schema), it knows nothing about resources or fields. Vendor extensions
  (`x-…`) are first-class, so the projector can attach `x-enum-varnames` and friends.
  The rest of the VO model (`OpenApi`, `Components`, `PathItem`, `Operation`, `Parameter`,
  `RequestBody`, `Response`, `Tag`, `SecurityScheme`, …) mirrors the OAS 3.1 object set
  the same way.

- **`SchemaProjector`** — maps a [field](fields.md) and its declared
  [constraints](constraints.md) to a `Schema`. A structural constraint self-describes
  its keyword via [`ProvidesJsonSchema`](../src/Resource/Constraint/ProvidesJsonSchema.php),
  so the projector shares one constraint→keyword source of truth with the
  body-validation `SchemaCompiler` (rather than mirroring it) — but emits a
  **standalone, OpenAPI-shaped** schema on top: `description` / `example`, full
  nullable handling, enum var-names, and the complete attributes / resource-object
  shapes. A constraint that has no faithful JSON Schema
  2020-12 keyword (a `When` with an opaque condition, `CompareField`, a date/time bound
  from `After` / `Before` / `Between`) is **never emitted as a wrong keyword**; instead a
  human-readable note is appended to the schema `description`. This lossy-by-design
  degradation keeps the document honest.

- **`OperationProjector`** — projects one type's HTTP surface into `PathItem`s: the
  resource-level `GET` / `POST` on `/{uriType}` and `GET` / `PATCH` / `DELETE` on
  `/{uriType}/{id}` (honouring the per-type operation allow-list), each relation's
  exposed related and relationship endpoints, and its custom actions under the
  `-actions` segment. Each operation enumerates its concrete query parameters
  (`filter[…]`, `sort`, `include`, `fields[…]`, `page[…]`, `withCount`), its request body,
  and the standard error responses, and carries its tags and per-operation security.

- **`OpenApiProjector`** — the top-level entry point. It consumes a
  `ServerMetadataInterface` and returns one `OpenApi` document: the skeleton
  (`openapi` / `info` / `servers` / `tags` / security schemes), the full component set
  (per-type attributes, resource object, resource identifier, create/update request
  schemas, per-relationship relationship objects, and the single / collection /
  relationship / related document envelopes), the shared components (`JsonApi`, `Meta`,
  `Links`, `PaginationLinks`, `Error`, `ErrorDocument`), the named enum components, the
  optional [Atomic Operations](atomic-operations.md) extension components + path, and —
  via the `OperationProjector` — the `paths`.

Component **names** follow stable PascalCase conventions (`blog-post` → `BlogPost`, so
`BlogPostResource`, `BlogPostCreateRequest`, `BlogPostAuthorRelationship`, …), shared
between the schema and path projections so every `$ref` resolves. The document is
projected once per server: a multi-server API produces one document per server, each
carrying only that server's types.

### What the schemas capture

The projection is faithful to the **runtime** the server actually serves, not a rough
sketch:

- **Context-correct attributes.** A field's visibility and its `required` set differ by
  representation, so the read, create and update shapes are *distinct* components
  (`<Type>Attributes`, `<Type>CreateAttributes`, `<Type>UpdateAttributes`). A read-only
  field is `readOnly: true`; a create-required one appears in the create shape's
  `required` but not the update shape's (an absent update member means "no change").
  The create/update components are emitted **only** when the type's operation allow-list
  exposes that write, so a read-only type dangles no write components.
- **Id policy.** A create request `$ref`s the create attributes and shapes `id`
  according to the type's client-id policy: **forbidden** (a `false` schema) when the
  server assigns it, **permitted** when a client id is allowed, **required** when it is
  mandatory — so a client generated from the spec can never send an `id` the runtime
  would reject.
- **Relationships.** Each declared relation gets a relationship-object component with its
  linkage `data` (a single nullable identifier for a to-one, an array for a to-many; a
  polymorphic relation's identifier is the `oneOf` of its members). A write request lists
  only the relations settable in that write. Endpoints are emitted per relation, gated by
  its endpoint-exposure and mutation flags.
- **Pagination, filters, sorts, includes.** A collection `GET` advertises its `page[…]`
  parameters (per the resolved paginator kind), its declared `filter[…]` value schemas,
  its `sort` keys, and `include` — the last only when the type actually exposes an
  includable path, so `?include` is never advertised where the runtime would reject it.

## The metadata contract

Core owns the JSON:API→OAS *semantics*, but most of the *data* — the API title and base
URLs, server assignment, tag definitions, security schemes, and the type/relation/action
inventory — is app- and framework-side. So the projector reads its input through a small
family of **read-only interfaces** in `haddowg\JsonApi\OpenApi\Metadata`. A framework
integration implements these from its compiled registry; core projects purely against
them.

| Interface | Describes |
|---|---|
| `ServerMetadataInterface` | one server: `info` (title / version / description / contact / license), `servers` (base URLs), the JSON:API version, tag definitions, security schemes + document-level default security, external docs, the type list, and the optional Atomic Operations endpoint |
| `TypeMetadataInterface` | one type: `type` / `uriType`, the field inventory (may be absent for a standalone serializer), relations, the operation allow-list (+ which operations are secured / public), the id policy (`allowsClientId` / `requiresClientId` / `idPattern`), paginator kind + countability, filters, sorts, actions, tags, description (+ per-operation description overrides), and includable paths |
| `RelationMetadataInterface` | one relation: name, related type(s), cardinality, endpoint exposure + mutation flags, per-relation security, paginator kind, filters/sorts (for a queryable to-many), pivot fields, and description |
| `ActionMetadataInterface` | one custom action: path, methods, scope, input/output mode + types, whether it is secured, tags, summary, description |

Discriminator enums (`OperationType`, `PaginatorKind`, `ActionScope`,
`ActionInputMode`, `ActionOutputMode`) round out the contract. The accessors that return
OAS value objects (`servers()`, `tags()`, `securitySchemes()`) hand the projector
ready-made VOs — that data is config-shaped, with no JSON:API semantics to interpret —
while type / relation / action data, which *does* carry semantics the projector must
interpret, flows through the interface family above.

A **standalone serializer** with no declared field inventory is tolerated
(`hasFields()` is `false`, `fields()` is empty): it projects to a permissive
resource-object schema and no attribute / write-request components, exactly mirroring the
runtime's "serializer but no fields" case.

## Authoring the schemas: `describedAs()` and `example()`

Two [field](fields.md) builder methods shape a field's projected schema. They are
render-neutral (the runtime ignores them) — they exist purely so the generated document
reads well:

```php
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Integer;

public function fields(): array
{
    return [
        Id::make(),
        Str::make('title')
            ->describedAs('The human-readable track title.')
            ->example('Paranoid Android'),
        Integer::make('duration')
            ->describedAs('The track length, in seconds.')
            ->example(383),
    ];
}
```

- **`describedAs(string)`** sets the schema `description`. (The setter is named to match
  the filters' `describedAs()`; the read-back getter is `getDescription()`.)
- **`example(mixed)`** sets the schema `example`. A declared `null` is honoured and is
  distinct from "no example".

The same `describedAs()` is available on [filters](filters.md#validating-filter-values)
(each convenience filter ships a generated default you can override) and on
[relations](relations.md) via `describedAs()`. Beyond fields, every documentable element
gets a **generated default description** the author may override declaratively: a type's
resource object via its `description()`, and each CRUD / related / relationship operation
via a per-operation description override — the projector emits the author's value when
present, a short generated sentence otherwise, never a blank. Because a request-time,
per-caller condition cannot be baked into a cached schema, a *conditionally* hidden or
prohibited field/verb is documented as the **superset** — the union of what any caller
may see or do (see the [request-aware predicates](fields.md) note).

## Vendor extensions

The projection emits a few JSON:API-specific OpenAPI **vendor extensions** (`x-…`
keywords) to carry information the base OAS vocabulary cannot.

### Backed enums: `x-enum-varnames` / `x-enum-descriptions`

When a field constrains its value to a PHP **backed enum** (via `In` with an enum
class-string, e.g. the `enum()` field builder), the projected `enum` schema is hoisted
into a **reusable named component** (`#/components/schemas/<Enum>`) and `$ref`'d — so the
same enum shared by several fields is one component, not a repeated inline list. That
component carries:

- **`x-enum-varnames`** — the enum **case names**, aligned to the `enum` values, so a code
  generator can emit a typed enum with meaningful member names rather than raw values.
- **`x-enum-descriptions`** — present when the enum implements `DescribedEnum`: a
  per-case description list (and a markdown `value → description` table folded into the
  schema `description`), so each case documents its meaning.

An `EnumDescriptionMode` controls whether the projector emits the markdown table, the
structured `x-enum-*` extensions, or both (the default).

### Profile-gated parameters: `x-profile`

Some query parameters are recognised by the runtime **only when a JSON:API
[profile](profiles.md) is negotiated**. The `withCount` parameter is the built-in case:
it is honoured only under the [Countable profile](profiles/countable.md). Rather than
advertise such a parameter unconditionally (which would mislead a client that has not
negotiated the profile), the projector marks it with **`x-profile: <profile-URI>`** —
naming the profile whose negotiation activates it. A tool that understands the extension
can surface the parameter conditionally; a tool that does not simply ignores an unknown
`x-` keyword. The parameter's `description` also states the profile requirement in prose,
so the constraint is never hidden behind the extension alone.

## Related pages

- [Fields](fields.md) / [field types](field-types.md) — the builder surface
  `describedAs()` / `example()` extend, and how each field type projects.
- [Constraints](constraints.md) — the validation vocabulary the `SchemaProjector` maps to
  JSON Schema keywords (and the lossy-degradation cases).
- [Filters](filters.md) — a filter's value schema, its generated description, the
  `Range` / `DateRange` `deepObject` parameter, and `DescribesQueryParameter` for a
  custom filter's own structured parameter shape.
- [Profiles](profiles.md) / [Countable profile](profiles/countable.md) — the profile
  mechanism behind the `x-profile` extension.
- [Atomic Operations](atomic-operations.md) — the extension whose request/result
  components and `POST` path the projector adds when it is enabled.
- The **Symfony bundle**'s OpenAPI docs — serving the document, configuration, the UI,
  and decorating the generated document.
