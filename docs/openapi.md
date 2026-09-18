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
schemas (`describedAs()` / `example()`), the per-code error catalogue, and the vendor
extensions it emits (`x-generator`, `x-enum-*`, `x-profile`). For *serving* the document,
the config, and the UI, see the Symfony bundle's OpenAPI docs.

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
  human-readable note is appended to the schema `description`. The same rule governs a
  date/time field's own `format` keyword. This lossy-by-design degradation keeps the
  document honest.

- **`OperationProjector`** — projects one type's HTTP surface into `PathItem`s: the
  resource-level `GET` / `POST` on `/{uriType}` and `GET` / `PATCH` / `DELETE` on
  `/{uriType}/{id}` (honouring the per-type operation allow-list), each relation's
  exposed related and relationship endpoints, and its custom actions under the
  `-actions` segment. Each operation enumerates its concrete query parameters
  (`filter[…]`, `sort`, `include`, `fields[…]`, `page[…]`, `withCount`), its request body,
  its declared success responses (see [Response declarations](#response-declarations)) and
  the standard error responses, and carries its tags and per-operation security.

- **`ErrorCatalogProjector`** — projects core's error codes into one named schema
  component each, and the `anyOf` that offers them from `ErrorDocument.errors.items`. See
  [The error-code catalogue](#the-error-code-catalogue).

- **`OpenApiProjector`** — the top-level entry point. It consumes a
  `ServerMetadataInterface` and returns one `OpenApi` document: the skeleton
  (`openapi` / `info` / `servers` / `tags` / security schemes), the full component set
  (per-type attributes, resource object, resource identifier, create/update request
  schemas, per-relationship relationship objects, and the single / collection /
  relationship / related document envelopes), the shared components (`JsonApi`, `Meta`,
  `Links`, `PaginationLinks`, `Error`, `ErrorDocument`), the per-code error variants, the
  named enum components, the optional [Atomic Operations](atomic-operations.md) extension
  components + path, and — via the `OperationProjector` — the `paths`.

Component **names** follow stable PascalCase conventions (`blog-post` → `BlogPost`, so
`BlogPostResource`, `BlogPostCreateRequest`, `BlogPostAuthorRelationship`, …), shared
between the schema and path projections so every `$ref` resolves. The document is
projected once per server: a multi-server API produces one document per server, each
built from that server's registered types.

### Which types a document describes

A server's document describes the types registered on that server, and a resource object
is the shape claim that only a registration can back. So a relation that exposes its
related endpoint must point at a type this server registers. `GET /favorites/{id}/user`
returns a `users` resource object as primary data, and with `users` unregistered here
there is no field inventory to describe one.

The projector refuses that configuration rather than publishing a guess:

```
haddowg\JsonApi\OpenApi\RelatedTypeNotRegistered

Relation "user" on type "favorites" exposes its related endpoint to type "users",
which is not registered on server "Music Catalog API". …
```

Three ways out, all of them honest:

- **register the type on this server** — it then projects from its own fields;
- **point the relation at a type that is registered** — a reduced second type
  (`public-profiles` beside a full `users`) where the real one belongs elsewhere;
- **call `->withoutRelatedEndpoint()`** — linkage-only, which needs no shape at all.

That last case is fully supported and unchanged. A related type reached only as linkage
gets a `<RelatedType>ResourceIdentifier` and no resource object, registered or not: an
identifier is `{type, id}` and asserts nothing about the resource behind it.

Two servers describing the same JSON:API `type` differently is a different thing
entirely, and stays supported — a v2 server serving a reshaped `users` beside v1 is the
versioning pattern, and each document states a shape its own server honours.

`ProjectedTypes` is the accessor for the described set:

```php
use haddowg\JsonApi\OpenApi\ProjectedTypes;

ProjectedTypes::registered($server);   // ['favorites']  — projected from their own fields
ProjectedTypes::relatedOnly($server);  // []             — non-empty means project() refuses
ProjectedTypes::forServer($server);    // ['favorites']
```

A framework integration emits a second artifact from the same metadata — the per-type JSON
Schema bundle served at `/schemas.json` — and keys it from `forServer()`, so the two
artifacts describe one agreed type set. `relatedOnly()` is the diagnostic: read it to fail
a build before the export runs.

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
  its endpoint-exposure and mutation flags — and a mutating verb on
  `/{type}/{id}/relationships/{rel}` additionally needs the *parent* type's allow-list to
  expose `Update`, since that is what it is. A read-only type's relation flags never open
  one.
- **Pagination, filters, sorts, includes.** A collection `GET` advertises its `page[…]`
  parameters (per the resolved paginator kind), its declared `filter[…]` value schemas,
  its `sort` keys, and `include` — the last only when the type actually exposes an
  includable path, so `?include` is never advertised where the runtime would reject it.
- **`?include` / `fields[…]` on writes.** These two shape the *rendered document*, not the
  query that selects it, so they belong to every operation that answers with a resource
  document — a `POST` create and a `PATCH` update as much as a `GET`, with the identical
  allowed-value enums, because the legal paths and members are a property of the type. A
  write that returns no such document takes neither: a `204` create or update, a delete
  (`204` or a meta-only `200`), and a relationship-endpoint mutation, whose echoed
  document is linkage and carries no `included` however the request is spelled. A custom
  action follows its declared body: one answering with a named type's document advertises
  **that** type's pair, even when it is mounted on another.
- **Dates and times that mean it.** `format: date-time`, `date` and `time` are RFC 3339
  productions, so a [`DateTime` / `Date` / `Time`](field-types.md#datetime) field gets
  one only when its configured serialization format really writes that shape. A field
  that writes anything else is documented as a plain `string` and its shape is given by
  example in the `description`, so a generated client never coerces on a keyword the
  server does not honour. Note that `Time`'s `H:i:s` default carries no offset and so is
  not an RFC 3339 `full-time`.

### The error-code catalogue

Every [typed exception](errors-and-exceptions.md) core raises carries a stable `code`, a
fixed HTTP status, a default title and — usually — a known `source` member. The
projection publishes each one as its own schema component, so a generated client can
offer a typed exception per code instead of an opaque string to `switch` on:

```yaml
components:
  schemas:
    FilteringUnrecognizedError:
      title: Filtering parameter is unrecognized
      allOf:
        - $ref: '#/components/schemas/Error'
        - type: object
          properties:
            code:   { type: string, const: FILTERING_UNRECOGNIZED }
            status: { type: string, const: '400' }
            source: { type: object, required: [parameter] }
          required: [code, status, source]
```

A variant **narrows** the shared `Error` rather than restating it, so the member
vocabulary lives in one place. `code` and `status` are pinned to a `const` — they are the
machine and HTTP contract and no [message resolver](errors-and-exceptions.md#localizing-and-overriding-error-copy)
can change them. `title` stays a plain string, because a resolver replaces it per locale;
core's default is the schema's `title` annotation. `source` names the member the exception
always fills, so a client can read `source.parameter` without a null check on the codes
that guarantee it.

What the variant does **not** carry is the error's interpolation context. The
`{placeholder}` tokens core fills into `title` and `detail` are resolved before the
response leaves, so the context itself never reaches a client. Describing its shape in a
document a client reads would describe something it can never receive. Those tokens are a
PHP-side API and live on the
[descriptor](errors-and-exceptions.md#which-placeholders-a-code-offers), where whoever
writes a replacement template is already working.

`ErrorDocument.errors.items` then offers the catalogue:

```yaml
items:
  anyOf:
    - $ref: '#/components/schemas/Error'          # the open branch
    - $ref: '#/components/schemas/FilteringUnrecognizedError'
    - …
```

**The first branch is the generic `Error`, and it is load-bearing.** Your application
throws error codes this projection has never heard of, and a closed `oneOf` would make
those documents fail your own published schema. The `anyOf` is a **catalogue, not a
constraint**: it constrains nothing, and that is the point. A generator reads the named
variants for the codes it can type and falls back to a status-based exception, raw code
intact, for anything else. ([ADR 0136](adr/0136-the-projected-error-code-catalogue-is-open.md).)

The catalogue is registration-aware, like the rest of the document: a code that depends
on a capability your server does not offer is left out entirely. The atomic and local-id
codes need the [Atomic Operations](atomic-operations.md) extension; `CURSOR_MALFORMED` /
`CURSOR_STALE` need a cursor-paginated collection; `PAGINATION_KIND_UNKNOWN` needs a
`MultiPaginator` menu; `RELATIONSHIP_COUNT_NOT_ALLOWED` needs the Countable profile;
the client-id codes need a type that permits one; and the whole request-document family
(bad JSON, missing `data`, an unacceptable `type`, …) needs a server that accepts a
request body somewhere — a CRUD write, a mutable relationship endpoint, or the atomic
batch.

**Adding your own codes to the catalogue.** Implement `DescribedErrorInterface` on your
exception and build its errors through the descriptor:

```php
use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorContextType;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorSourceShape;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class SeatsSoldOut extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $performance)
    {
        parent::__construct("No seats remain for '$performance'.", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'SEATS_SOLD_OUT',
            status: 409,
            title: 'Seats are sold out',
            context: ['performance' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(
            detail: "No seats remain for '{performance}'.",
            context: ['performance' => $this->performance],
            source: ErrorSource::fromPointer('/data/relationships/performance'),
        )];
    }
}
```

Core projects its own catalogue; an integration decides whether and how to feed yours
into the document it builds.

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
| `TypeMetadataInterface` | one type: `type` / `uriType`, the field inventory (may be absent for a standalone serializer), relations, the operation allow-list (+ which operations are secured / public), the id policy (`allowsClientId` / `requiresClientId` / `idPattern`), the `page` value schema (`pageSchema`) + countability, filters, sorts, actions, tags, description (+ per-operation description overrides), includable paths, and per-operation response declarations (`responsesFor`) |
| `RelationMetadataInterface` | one relation: name, related type(s), cardinality, endpoint exposure + mutation flags, per-relation security, the `page` value schema (`pageSchema`), filters/sorts (for a queryable to-many), pivot fields, and description |
| `ActionMetadataInterface` | one custom action: path, methods, scope, input mode + type, its `responds` response set, whether it is secured, tags, summary, description |

The `pageSchema()` accessors carry the resolved paginator's self-described `page[…]`
object schema (a `oneOf` menu for a `MultiPaginator`), or `null` when the collection is
unpaginated — so the projector emits the whole `page` family as one `deepObject`
parameter without a central paginator switch. Discriminator enums (`OperationType`,
`ActionScope`, `ActionInputMode`) round out the contract. The accessors that return
OAS value objects (`servers()`, `tags()`, `securitySchemes()`) hand the projector
ready-made VOs — that data is config-shaped, with no JSON:API semantics to interpret —
while type / relation / action data, which *does* carry semantics the projector must
interpret, flows through the interface family above.

A **standalone serializer** with no declared field inventory is tolerated
(`hasFields()` is `false`, `fields()` is empty): it projects to a permissive
resource-object schema and no attribute / write-request components, exactly mirroring the
runtime's "serializer but no fields" case.

## Response declarations

By default every operation advertises the single success response it has always emitted — a
create `201` (with `Location` and the created document), an update `200`, a delete `204`, a
fetch `200`, a collection `200`. A type may **override** the success set an operation
advertises, and a custom action **declares** its own, through a small family of immutable
response objects in `haddowg\JsonApi\OpenApi\Metadata`. This is how a spec-valid `204`, an
asynchronous `202`, or a `303` completion enters the contract (ADR 0126, ADR 0127).

### The response objects

Each object names one HTTP status an operation may return; a declaration is a **list** of
them (a set). They are typed per operation so an illegal combination is unrepresentable:

| Object | Status | Body / headers | Valid on |
|---|---|---|---|
| `new Created()` | `201` | created resource document + `Location` | create |
| `new Ok()` | `200` | resource / collection document | update, fetch-one, fetch-collection |
| `new NoContent()` | `204` | no body | create, update, delete, actions |
| `new Accepted(string $jobType)` | `202` | the **job** type's document + `Content-Location` + `Retry-After` | create, update, actions |
| `new SeeOther()` | `303` | `Location` only, no body | fetch-one, actions |
| `new MetaResult()` | `200` | a meta-only document | delete, actions |
| `new ActionResource(string $type)` | `200` | the named type's document | actions |

Each implements a per-operation **marker interface** — `CreateResponse`, `UpdateResponse`,
`DeleteResponse`, `FetchOneResponse`, `FetchCollectionResponse` for CRUD, and
`ActionResponse` for custom actions — so a value only type-checks where it is spec-valid
(`new SeeOther()` is a `FetchOneResponse`, never a `CreateResponse`). The helpers
`OperationResponses` (`defaultFor()` / `validate()`) and `ActionResponses` (`validate()`)
supply the default set and reject a malformed one: empty, a duplicate status, more than one
job-bearing `202`, or a status outside the operation's spec-valid range.

The metadata contract carries the resolved sets:
`TypeMetadataInterface::responsesFor(OperationType)` returns a type's success set for a
CRUD/read operation (its declared override, else `OperationResponses::defaultFor()`), and
`ActionMetadataInterface::responds()` returns an action's. The `OperationProjector` emits
one `Response` per element, keyed by status — and the unoverridden default reproduces the
exact response the document has always carried, so an existing contract is byte-for-byte
unchanged.

### The asynchronous-write lifecycle

Together the objects reflect the JSON:API
[asynchronous processing](https://jsonapi.org/recommendations/#asynchronous-processing)
recommendation in the generated document:

- A write accepted for background processing advertises **`new Accepted('jobs')`** — a
  `202` whose body is the `jobs` type's document (that type must be registered so its
  schema exists), plus a `Content-Location` header (the job URL to poll) and a
  `Retry-After` hint.
- The client polls the job resource; **completion** is a `303 See Other` to the produced
  resource. Model it either as a **fetch-one** on the job type declaring
  `[new Ok(), new SeeOther()]` (the spec-canonical shape: `200` with the job's status while
  it runs, `303` when done) or as a **custom action** declaring
  `[new Accepted('jobs'), new SeeOther()]`.

Because the `303` is a **runtime** decision, a fetch-one that may redirect implements the
read seam **`haddowg\JsonApi\Resource\ResolvesCompletionRedirect`**:
`completionLocation(object $entity): ?string` returns the produced resource's URL when the
work is complete (the handler then renders a `303`) or `null` for the normal `200`. The
declaration documents the `303`; the seam performs it.

### Authoring them

Core defines and projects these objects; the **framework integrations** let you declare
them on a resource or action — see the response-declaration sections of the
[Symfony bundle](https://github.com/haddowg/json-api-symfony) and
[Laravel package](https://github.com/haddowg/json-api-laravel) documentation.

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

The projection emits a few OpenAPI **vendor extensions** (`x-…` keywords) to carry
information the base OAS vocabulary cannot.

### The generator contract: `x-generator`

Every projected document stamps its `info` with one integer:

```json
"info": {
  "title": "Music API",
  "version": "1.2.0",
  "x-generator": { "contract": 2 }
}
```

`contract` is the version of the **emitted structure**, and it moves only when that
structure moves. It is not the package version, and reading it as one would be wrong:
`1.4.2 → 1.4.3` may emit exactly the same document while a minor release adds a member,
so a code generator keying on semver would accept or reject for reasons unrelated to what
it actually reads.

It is nevertheless **release-scoped**, which is the part that matters when you write the
generator. The contract names the shape a *release* emits, so it moves at most once per
release however many changes that release contains. Two documents carrying the same
contract have the same structure; consecutive contracts differ by one release's worth of
change, not by one commit's.

**A document with no `x-generator` at all is contract 1.** Version 1.0.0 shipped before
the field existed, so contract 1 denotes the shape it emitted rather than "unknown" — a
generator declaring `[1, 2]` reads every document this library has ever produced.

A code generator declares the range of contracts it understands and compares:

| `contract` | verdict |
| --- | --- |
| absent | Treat as 1 — a pre-`x-generator` document. |
| below the generator's minimum | **Error.** The server is older than a structure the generator requires. |
| within the range | Generate. |
| above the generator's maximum | **Warn.** The server describes capabilities the generator cannot read. |

The upper bound is the one that needs the field. A server *older* than a generator expects
already fails loudly: the generator reads for a member, finds it absent, and says so. A
server *newer* fails silently — the unread structure simply is not generated, and the
client comes out missing capabilities the server offers with nothing to indicate it. That
silent under-generation is what `x-generator` exists to catch.

It carries compatibility signalling and nothing else. The JSON:API version is the
`JsonApi` component's `version` const, and the supported profiles and extensions are that
component's `profile` / `ext` enums; none of it is restated here. There is deliberately no
feature-token list either: naming what changed would mean two hand-maintained lists (the
server's and every generator's known-set) that have to agree forever, and a drifting token
list is worse than an integer that cannot drift.

The bump is a maintainer decision, and CI enforces that it gets made. A guard compares the
projected structure against the last release tag: a cycle that moves the structure must
move the contract past what that release shipped, and once it has, later changes in the
same cycle leave it alone. See the contract discipline in `CLAUDE.md`.

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
