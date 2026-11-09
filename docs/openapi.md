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
extensions it emits (`x-generator`, `x-enum-*`, `x-profile`, `x-safety`, `x-sort-families`). For *serving* the document,
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
  its declared success responses (see [Response declarations](#response-declarations)) and a
  `$ref` per standard error status, and carries its tags and per-operation security.

- **`ErrorCatalogProjector`** — groups the server's error codes into one named shape per
  status, `source` member and `meta` declaration, each code a `const` branch of its shape,
  and the `anyOf` that offers the shapes from `ErrorDocument.errors.items`. See
  [The error-code catalogue](#the-error-code-catalogue).

- **`ErrorResponseProjector`** — projects the `components.responses` entry every operation's
  error `$ref`s resolve to, one per advertised status, each pointing at the narrowest error
  document the catalogue supports. See [The shared error responses](#the-shared-error-responses).

- **`OpenApiProjector`** — the top-level entry point. It consumes a
  `ServerMetadataInterface` and returns one `OpenApi` document: the skeleton
  (`openapi` / `info` / `servers` / `tags` / security schemes), the full component set
  (per-type attributes, resource object, resource identifier, create/update request
  schemas, per-relationship relationship objects, and the single / collection /
  relationship / related document envelopes, and a type's own links component when it has
  named links), the shared components (`JsonApi`, `Meta`, `LinkObject`, `RenderedLink`,
  `Links`, the per-context links components, `Error`, `ErrorDocument` and its per-status
  narrowings), the
  per-code error variants, the shared error responses, the named enum components, the
  optional [Atomic Operations](atomic-operations.md) extension components + path, and —
  via the `OperationProjector` — the `paths`.

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
- **Closed objects.** Every attributes component and every nested object a field declares
  (`Map`, `Obj`, each `OneOf` branch, a structured list's element) carries
  `additionalProperties: false`, so a member the type does not declare is rejected rather
  than silently dropped. A nested object a hook produces stays open in that direction:
  `serializeUsing()`/`extractUsing()` on a read, `fillUsing()`/`deserializeUsing()` on a
  write, since the hook, not the declared children, owns its shape. A standalone
  serializer with no field inventory keeps an open attributes object.
- **Read-only children.** A nested object's child that is read-only for a write is left
  out of that write's shape, as a read-only attribute is, in a `Map`, an `Obj`, each
  `OneOf` branch and a structured list's element. A child read-only only by a `when:`
  predicate stays. The request validator `SchemaCompiler` builds from the same projection
  but keeps such a child as an optional member, because hydration ignores it rather than
  rejecting it, so a client echoing a fetched value back still validates.
- **Id policy.** A create request `$ref`s the create attributes and shapes `id`
  according to the type's client-id policy: **forbidden** (a `false` schema) when the
  server assigns it, **permitted** when a client id is allowed, **required** when it is
  mandatory — so a client generated from the spec can never send an `id` the runtime
  would reject.
- **Relationships.** Each declared relation gets a relationship-object component with its
  linkage `data` (a single nullable identifier for a to-one, an array for a to-many; a
  polymorphic relation's identifier is a `oneOf` of its members with a `type`
  discriminator, even when it has only one, see
  [polymorphic relations](#polymorphic-relations-with-one-type)). A write request lists
  only the relations settable in that write: a create leaves out a relation that is
  `readOnly()` or `readOnlyOnCreate()`, and an update one whose replacement is refused. A
  relation read-only only by a `when:` predicate stays listed, as such an attribute
  does. The request example follows the same rule. A write request marks a write-only relation's property
  `writeOnly: true`, so a generator can keep it on its input types and off its read types.
  Endpoints are emitted per relation, gated by
  its endpoint-exposure and mutation flags — and a mutating verb on
  `/{type}/{id}/relationships/{rel}` additionally needs the *parent* type's allow-list to
  expose `Update`, since that is what it is. A read-only type's relation flags never open
  one. A relation's paths use its [URI field name](relations.md#conventional-links), the
  segment its links and routes use, so `withUriFieldName('by')` documents
  `/{type}/{id}/by`.
- **Pivot data, per direction.** A `belongsToMany` relation's linkage identifiers type
  their `meta.pivot` with one of two components. `<Type><Rel>Pivot` is what a response
  renders: every pivot field except a write-only one, each `required` unless its read
  visibility is request-conditional, and `null` admitted unless the value is guaranteed,
  as for an attribute. `<Type><Rel>WritePivot` is what a request may send: only the fields
  a write can set, with the ones every attach must supply in `required`. The response
  linkage reaches the first through `<Type><Rel>Relationship` and
  `<Type><Rel>RelationshipDocument`; the create, update and atomic bodies reach the second
  through `<Type><Rel>WriteRelationship`, and the relationship `PATCH` / `POST` / `DELETE`
  bodies through `<Type><Rel>RelationshipRequest`.
- **Links, per context.** Each place a link renders has one shared component listing the
  members the server can put there: `ResourceLinks`, `DocumentLinks`, `CollectionLinks`,
  `RelationshipLinks`, `RelationshipDocumentLinks` and `RelatedDocumentLinks`. A type whose
  resources carry [declared links or action links](#links-and-describedby) gets its own
  `<Type>ResourceLinks`, extending `ResourceLinks` with them. See
  [links and `describedby`](#links-and-describedby).
- **A typed `included`.** A compound document's `included` is an array whose items are a
  `oneOf` over the `<Type>Resource` of every type its `?include` paths reach, with a
  `type` discriminator mapping each type to its component. The reach is the one the
  operation's `include` enum and `fields[<type>]` parameters use: every type a listed path
  ends at, walked through the relation graph and through each member of a polymorphic
  segment, leaving out a type the server does not register. A related endpoint's
  `included` is rooted at the related type. A document whose paths reach nothing has no
  `included` member.
- **Atomic operations by kind.** With the [Atomic Operations](atomic-operations.md)
  extension on, `AtomicOperation` is a `oneOf` over one component per kind:
  `AtomicAddOperation`, `AtomicUpdateOperation` and `AtomicRemoveOperation` for a resource,
  and `AtomicRelationshipAddOperation`, `AtomicRelationshipUpdateOperation` and
  `AtomicRelationshipRemoveOperation` for a relationship. Each pins its `op` as a `const`,
  and a relationship kind needs a target that names the relationship, a `ref` with
  `relationship` or an `href` ending in `relationships/<name>`, which a resource kind
  refuses, so a valid operation matches exactly one kind. An add or update carries a
  `oneOf` over `<Type>AtomicAdd` or `<Type>AtomicUpdate`, and a relationship kind carries
  `<Type>AtomicIdentifier`s, which take an `id` or a `lid`, all discriminated by `type`.
  The relationships inside an add or update carry the same identifiers, so they can link
  a resource an earlier operation created.
  An `AtomicResult`'s `data` is the `anyOf` of the returned resource, discriminated over
  `<Type>Resource`, and the returned linkage, discriminated over
  `<Type>ResourceIdentifier`, since a resource with no attributes is also an identifier.
- **Pagination, filters, sorts, includes.** A collection `GET` advertises its `page[…]`
  parameters (per the resolved paginator kind), its declared `filter[…]` value schemas,
  its `sort` keys (and the patterns of its [sort families](#sort-families-x-sort-families)), and `include` — the last only when the type actually exposes an
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
- **Filter values that carry a type.** A `filter[<key>]` gets its **container** shape from
  the filter kind — a set is an array, a range a `min`/`max` object, a presence-only filter
  a plain string whose description says the value is ignored — and its **value** schema from
  the filter's declared [value constraints](filters.md#validating-filter-values). A filter
  that declared none falls back to the JSON type of the field it targets, matching the
  filter's column against the type's field inventory: a `Where` over an `Integer` documents
  as `{"type": "integer"}` where it used to document as `{}`. Only the type carries over —
  not the field's `format`, `enum` or length, which describe a document member rather than a
  comparison operand. Where the column resolves to no single scalar field — a relationship
  path, a group spanning several columns, a computed value, a composite — the value is
  `string`, which is what a query parameter is on the wire regardless.
- **Dates and times that mean it.** `format: date-time`, `date` and `time` are RFC 3339
  productions, so a [`DateTime` / `Date` / `Time`](field-types.md#datetime) field gets
  one only when its configured serialization format really writes that shape. A field
  that writes anything else is documented as a plain `string` and its shape is given by
  example in the `description`, so a generated client never coerces on a keyword the
  server does not honour. Note that `Time`'s `H:i:s` default carries no offset and so is
  not an RFC 3339 `full-time`.
- **Constraints, per representation.** Every built-in constraint with a JSON Schema
  keyword projects it: `minimum` / `maximum` / `exclusiveMinimum` / `exclusiveMaximum` /
  `multipleOf`, `minLength` / `maxLength` / `pattern`, `minItems` / `maxItems` /
  `uniqueItems`, `minProperties` / `maxProperties`, `enum`, `not`, and the `email` / `uri`
  / `uuid` / `ipv4` / `ipv6` formats. Which schema carries a keyword follows the context it
  is enforced in. The create shape takes the constraints that apply on create, and the
  update shape those that apply on update. The read shape takes a constraint only when
  every write that can set the field enforces it, so a create-only `maxLength(280)` on a
  field an update can also write narrows the create shape and nothing else. A field only a
  create can write (`readOnlyOnUpdate()`) keeps its create-only bounds on read. `null` goes
  the other way: the read shape admits it when any write that can set the field does. A
  filter value takes every constraint the filter declares, whatever its context, because
  the filter validator runs them all.
- **`null` on read only when it can happen.** A response attribute admits `null` unless
  the server guarantees a value, because an optional member a create left out renders as
  `null`. A value is guaranteed when every create had to supply it (`required()` or
  `requiredOnCreate()` on a field a create can write, and no write declares `nullable()`),
  when the closure producing it declares a non-null return type, or when the field
  declares [`guaranteed()`](fields.md#null-on-read-guaranteed). A read-only field and an
  [`on()`](fields.md#flattened-related-attributes-on) value admit `null` until asserted. A
  nullable enum adds `null` to its `enum` list, or unions its `$ref` with `{"type":
  "null"}`; a nullable `OneOf` gains a `null` branch. `Obj` and `Map` children follow the
  same rule, and the resource `id` is never null. So a client can trust a read type
  without `null`: that member always carries a value.
- **Presence is a separate promise.** The read shape's `required` lists every attribute a
  response without `fields[<type>]` always carries, `null` or not. A `hidden(when:)` or
  `writeOnly(when:)` member and a `sparseByDefault()` one are left out, since a response
  can omit them. A sparse fieldset can omit any member, so a client that sends one
  narrows the type itself. The rule recurses into `Map`, `Obj` and `OneOf` values: a
  nested object lists every child it renders, leaving out a write-only child (absent from
  the read `properties` too) and a `hidden(when:)` / `writeOnly(when:)` one, and a
  discriminated `OneOf` branch also requires its discriminator. A nested object whose
  `serializeUsing()` or `extractUsing()` hook builds the value lists none, and a
  `OneOf` with such a hook stops requiring its discriminator, since the hook decides
  its shape. Its `properties` are still projected from the declared children. An
  `extractUsing()` value still renders through those children, but a
  `serializeUsing()` hook's return value goes out as it is, so keep it to the
  declared shape or the response won't match the documented one. A nullable nested
  object can still be `null`; when it is present, its required children are there.
- **`required()` means non-empty too.** A `Required` field must be present on create and
  may never be empty, so a required string carries `minLength: 1`, a required list
  `minItems: 1` and a required `ArrayHash` / `Map` / `Obj` `minProperties: 1`, in each
  shape the `Required` applies to (a larger declared minimum is kept). A required number
  or boolean gains nothing.
- **Conditional rules stay out of the keywords.** A constraint inside `when()` holds only
  for the requests its condition admits, so none of its keywords project. The schema gets
  a note in its `description` instead. A conditional `nullable()` does widen the type to
  admit `null`, since some requests may send it. An `Ip` that accepts either family has no
  `format`, because `ipv4` alone would reject its IPv6 addresses. An `anyOf` from
  `atLeastOneOf()` keeps every alternative whatever its context, since dropping one would
  reject values the server accepts.

### Polymorphic relations with one type

A `MorphTo` or `MorphToMany` is projected as a union of its member types even when it
declares only one. `MorphTo::make('subject', ['people'])` gets the same shape a
`MorphTo` over two types gets, with one member, where a `BelongsTo` to `people` gets a
plain `$ref`:

- its linkage identifier is
  `{"oneOf": [{"$ref": "#/components/schemas/PeopleResourceIdentifier"}], "discriminator": {"propertyName": "type", "mapping": {"people": "#/components/schemas/PeopleResourceIdentifier"}}}`,
  in the relationship object, the relationship document and the write bodies;
- the `data` of its related document or related collection is the same `oneOf` over the
  member resources, its discriminator mapping each type to its `<Type>Resource`;
- its related and relationship endpoints take no `filter`, `sort` or `page` parameters
  from the related type, and its related `?withCount` offers no relations of it. A
  relation's own filters and sorts, and the `fields[<type>]` parameters, are unchanged.

A `MorphToMany` declared with [`identifiedBy()`](relations.md#identifying-the-members-without-loading-them)
is the one exception: its relationship endpoint pages its stored pairs, so it takes the
same `page` and `withCount` parameters as its related endpoint, and still no `filter` or
`sort`. Metadata says so through `DeclaresIdentifiedMembers`; metadata that doesn't
implement it projects the endpoint with no parameters.

So a generated client already types the relation as polymorphic, and adding a second type
later adds a member rather than changing every input that carries the relation. The
projector learns that a relation is polymorphic from `DeclaresPolymorphicRelation` on its
[metadata](#the-metadata-contract); metadata that doesn't implement it decides by the
number of types, as before.

### The error-code catalogue

Every [typed exception](errors-and-exceptions.md) core raises carries a stable `code`, a
fixed HTTP status, a default title and, usually, a known `source` member. The projection
groups the codes into **shapes**: the codes at one status that fill the same `source`
member and declare the same `meta` share one schema component, and each code is a `const`
branch of that component's `code`. A generated client still offers a typed exception per
code, and a new code usually adds one branch rather than a component:

```yaml
components:
  schemas:
    Error400Parameter:
      title: A 400 error caused by a query parameter
      description: A `400` error. Its `code` is one of the branches below, … `source.parameter` names the query parameter at fault.
      allOf:
        - $ref: '#/components/schemas/Error'
        - type: object
          properties:
            code:
              anyOf:
                - type: string
                  const: FILTERING_UNRECOGNIZED
                  title: Filtering parameter is unrecognized
                  description: The request filtered on a key this endpoint does not declare. Filter only on the keys the API documents for this resource type.
                - type: string
                  const: QUERY_PARAM_UNRECOGNIZED
                  title: Query parameter is unrecognized
                  description: …
                - …
            status: { type: string, const: '400' }
            source: { type: object, required: [parameter] }
          required: [code, status, source]
```

A shape **narrows** the shared `Error` rather than restating it, so the member vocabulary
lives in one place. `code` and `status` are pinned, the code to one of its branches and the
status to a `const`: they are the machine and HTTP contract and no
[message resolver](errors-and-exceptions.md#localizing-and-overriding-error-copy) can change
them. `title` stays a plain string, because a resolver replaces it per locale; core's
default is the `title` annotation on the code's branch. `source` names the member every code
in the shape always fills, so a client can read `source.parameter` without a null check on
the codes that guarantee it. The `code` is an `anyOf` even when the shape holds one code, so
adding a code is always one more branch.

A shape is named for its status, then its `source` member, then, when it declares `meta`, its
first code: `Error404`, `Error422Pointer`, `Error406Header`, `Error403InsufficientScope`.

Each branch's `description` says what its code means and what a client should do about it,
which a generator can carry onto the typed exception it emits. Every code core ships has a
description; your own codes get one from the
[descriptor](errors-and-exceptions.md#telling-api-consumers-what-a-code-means), and a code
without one projects its branch without it. A code's example `detail`, `source` and `meta`
are in its entry of the error responses' [examples](#operation-examples), not on the schema.

**Meta.** A code whose descriptor [declares its `meta`](errors-and-exceptions.md#declaring-what-an-error-puts-in-meta)
gets a closed `meta` object built from those members, with the same field builders and the
same projection an attribute uses. `meta` is required when a member is declared `required()`
and optional otherwise. `INSUFFICIENT_SCOPE` shows both kinds:

```yaml
    Error403InsufficientScope:
      title: Insufficient scope
      allOf:
        - $ref: '#/components/schemas/Error'
        - type: object
          properties:
            code: { anyOf: [ { type: string, const: INSUFFICIENT_SCOPE, title: Insufficient scope, description: … } ] }
            status: { type: string, const: '403' }
            meta:
              type: object
              properties:
                requiredScopes: { type: array, items: { type: string }, description: Every scope the request needs. }
                missingScopes:  { type: array, items: { type: string }, description: The required scopes the access token does not grant. }
                include:        { type: string, description: … }
              required: [requiredScopes, missingScopes]
              additionalProperties: false
          required: [code, status, meta]
```

What a shape does **not** carry is the error's interpolation context. The
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
    - $ref: '#/components/schemas/Error400Parameter'
    - …
```

**The first branch is the generic `Error`, and it is load-bearing.** Your application
throws error codes this projection has never heard of, and a closed `oneOf` would make
those documents fail your own published schema. The `anyOf` is a **catalogue, not a
constraint**: it constrains nothing, and that is the point. A generator reads the named
shapes for the codes it can type and falls back to a status-based exception, raw code
intact, for anything else.

The catalogue is registration-aware, like the rest of the document: a code that depends
on a capability your server does not offer is left out entirely. The atomic and local-id
codes need the [Atomic Operations](atomic-operations.md) extension; `CURSOR_MALFORMED` /
`CURSOR_STALE` need a cursor-paginated collection; `PAGINATION_KIND_UNKNOWN` needs a
`MultiPaginator` menu; `RELATIONSHIP_COUNT_NOT_ALLOWED` needs the Countable profile;
the client-id codes need a type that permits one; and the whole request-document family
(bad JSON, missing `data`, an unacceptable `type`, …) needs a server that accepts a
request body somewhere — a CRUD write, a mutable relationship endpoint, or the atomic
batch.

### Scoping a code to the operations that raise it

Most codes can arrive on any operation, but an application's own codes are often endpoint
specific: a lock on an update, a payment check on one action. An error names the operations
it can arrive on with a `scope` on its descriptor:

```php
use haddowg\JsonApi\Exception\ErrorScope;

new ErrorDescriptor(
    code: 'ARTICLE_LOCKED',
    status: 409,
    title: 'Article locked',
    scope: ErrorScope::on('articles', 'update', 'delete'),
);
```

`ErrorScope::on()` names operations of one type in the grammar operation examples and
scopes are keyed by (`update`, `fetchRelated.author`, `action.publish.post`), or every
operation of the type when it names none. `ErrorScope::kinds()` covers operations of a kind
on every type, for an error that is not one type's, and `and()` combines scopes. The Atomic
Operations endpoint offers a code whose scope names `OperationKind::Atomic`, and one whose
scope covers a create, update, delete or relationship write, since a batch entry runs that
write through the same handler. A code scoped to reads stays off the batch. For an
error you do not own, catalogue it through a `ScopedErrorSource`, whose scope replaces the
descriptor's:

```php
new ScopedErrorSource(ErrorScope::on('articles', 'action.purchase.post'), PaymentRequired::class)
```

A scoped code is catalogued like any other, in a shape of its own scope named after its first
code (`Error409ArticleLocked`), and only the operations it is declared for offer it. Each of
them points its response for the code's status at one narrowed to the codes every operation
raises plus the scoped ones, `ConflictWithArticleLocked` carrying
`ErrorDocument409WithArticleLocked`, and gains that status if it did not advertise it
already. Operations raising the same scoped codes at a status share one such response, and
the rest keep the shared one. A name that is not an operation name fails when the scope is
built, and a scope naming a type the server does not register covers nothing on it.

Each code's example is one `components.examples` entry, keyed by the code, which every
response offering the code references, so a narrowed response adds a reference per code
rather than a copy.

### The shared error responses

An operation does not describe its error bodies. It `$ref`s one of the shared responses
under `components.responses`, named for the HTTP status:

```yaml
paths:
  /articles:
    post:
      responses:
        '201': { … }
        '422': { $ref: '#/components/responses/UnprocessableEntity' }

components:
  responses:
    UnprocessableEntity:
      description: Unprocessable Entity — the document failed validation.
      content:
        application/vnd.api+json:
          schema: { $ref: '#/components/schemas/ErrorDocument422' }
```

One response component per status your server advertises — nothing more, in keeping with
the rest of the document. Each is named for its status's reason phrase (`UnprocessableEntity`,
`PaymentRequired`, `TooManyRequests`, …); a status without one is named `Status<code>`. An endpoint that phrases a status its own way keeps its wording
on the `$ref` as an OAS 3.1 Reference Object `description`, which overrides the
component's; the [Atomic Operations](atomic-operations.md) batch does this for the six it
describes in terms of an operation within the batch rather than the request as a whole.

**Each response carries the narrowest error document its status supports.** Every
catalogued shape pins its `status` to a `const`, so `ErrorDocument422` is the error document
whose `errors.items.anyOf` offers only the shapes whose codes can arrive with a `422` — and
the open generic `Error` still leads it. A status the catalogue claims no code for keeps the
generic `ErrorDocument`, such as a status whose codes your feature set gated out. `401` and
`403` narrow like the rest: core catalogues `AUTHENTICATION_REQUIRED` and `ACCESS_DENIED`
for the adapters to render when the framework's security layer refuses a request, so
`ErrorDocument401` offers the one and `ErrorDocument403` offers the other beside any
write-side `403` codes.

Two things follow, and the second is easy to miss. The `anyOf` is still a catalogue and
still not a constraint — your own `422` code validates against `ErrorDocument422` for the
same reason it validates against `ErrorDocument`. And a document's status is the status
*class* its errors share: a response that collects a `422` and a `409` goes out as a `400`
([errors and exceptions](errors-and-exceptions.md)), so a `400`
body can carry an error object whose own `status` member reads `"422"`. The generic branch
absorbs that and validation passes. Read the per-status list as the codes that status
usually carries, not the codes it can only carry.

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

Then point the server at it. The catalogue is assembled from
[sources](errors-and-exceptions.md#contributing-your-codes-to-the-catalogue): core's own
always, plus whatever the server's metadata contributes through `ContributesErrorCodes`.
Contribution is per server, so `SEATS_SOLD_OUT` is documented on the servers that can
raise it and nowhere else, and two classes claiming one `code` are refused rather than
silently merged. The Symfony and Laravel integrations wire a source from their own
discovery, so in those apps the exception above is catalogued by writing it.

### Links and `describedby`

The server renders the links it builds as [link objects](links-and-meta.md#built-links-are-link-objects),
and the links components describe exactly that. There is one per context, shared by every
type and relation:

| Component | Used by | Members |
|---|---|---|
| `ResourceLinks` | each `<Type>Resource` without named links | `self` |
| `DocumentLinks` | each `<Type>Document` | `self`, `describedby` |
| `CollectionLinks` | each `<Type>Collection` | `self`, `describedby`, `first`, `prev`, `next`, `last` |
| `RelationshipLinks` | each `<Type><Rel>Relationship` | `self`, `related`, `first`, `prev`, `next`, `last` |
| `RelationshipDocumentLinks` | each `<Type><Rel>RelationshipDocument` | `self`, `related`, `first`, `prev`, `next`, `last`, `describedby` |
| `RelatedDocumentLinks` | each `<Type><Rel>RelatedDocument` and `<Type><Rel>RelatedCollection` | `self`, `describedby`, `first`, `prev`, `next`, `last` |

Each member points at `RenderedLink`, the object form the server renders (`href`, plus
`describedby` and `meta` when present), and none is required. `describedby` is listed only
when the metadata names the document's URL. A member is listed when some response in that
context can render it, so a to-one relationship's links admit pagination members it never
renders, and a relationship object whose relationship endpoint isn't exposed admits a
`self` it never renders; each component's description says when each member is present.
A component is emitted only when something references it, so a server without relations
has no `RelationshipLinks`. Error documents keep the generic `Links`.

```json
"CollectionLinks": {
  "type": "object",
  "description": "The top-level `links` of a document whose primary data is a collection. The pages are present when the collection is paginated.",
  "properties": {
    "self": { "$ref": "#/components/schemas/RenderedLink", "description": "The URL that produced the document." },
    "describedby": { "$ref": "#/components/schemas/RenderedLink", "description": "The OpenAPI operation that describes the document." },
    "first": { "$ref": "#/components/schemas/RenderedLink", "description": "The `first` page of the collection, when there is one." },
    "prev": { "…": "…" },
    "next": { "…": "…" },
    "last": { "…": "…" }
  },
  "additionalProperties": { "$ref": "#/components/schemas/LinkObject" }
}
```

`additionalProperties` stays the open `LinkObject` (a string, a link object or `null`),
because a `getLinks()` override can add any link in any shape. `RenderedLink`'s description
says once that a response's `href` is absolute to the request host unless the server pins
its links to a configured base URI.

The link schemas carry no examples. The [operation examples](#operation-examples) show
every document whole, links included: each link built from the document's first server
URL plus its path, with the type's `Id` example in place of `{id}`, and the pagination
links of page two under the paginator's configured keys. That is where a type's own URLs
appear. The `describedby` in an example is present only when the metadata names the
document's URL and the document carries the operation; it is built from the same path
templates the projector keys `paths` by, so the two can't drift.

`LinkObject` keeps an example of its own: the first registered type's resource URL with its
example id (else `1`), plus a `describedby` pointing at that type's fetch-one operation when
the server renders link objects and names its document URL. The generic `Links` that error
documents use keeps a one-link example, and the free-form `Meta` component an empty `{}`,
because a sampler would otherwise fill those open objects with invented members.

A type whose resources carry [declared links](links-and-meta.md#declared-links) or
[action links](links-and-meta.md#action-links) gets its own `<Type>ResourceLinks`: an
`allOf` of the shared `ResourceLinks` and an object holding one property per named link,
none of them required, since a link whose href resolves to `null` is left out. Its
`<Type>Resource` points there instead of at `ResourceLinks`. Each named link still
satisfies `ResourceLinks`' `additionalProperties`, since every link shape is a
`LinkObject`. A link with `meta` members or a static member, one of `rel`, `title`, `type`,
`hreflang` and `describedby`, gets its own object schema: `href`, each static member as a
`const`, and a `meta` object whose properties are the declared members, each projected the
way an attribute is on a read, with its type, constraints, description and example. `meta`
lists as required every member a request cannot hide, and the link lists `meta` when
there is one. A link with neither takes the open `LinkObject` union, as an author-supplied
link does:

```json
"FilesResourceLinks": {
  "description": "The `links` of a resource object of type `files`.",
  "allOf": [
    { "$ref": "#/components/schemas/ResourceLinks" },
    {
      "type": "object",
      "properties": {
        "upload": {
          "type": "object",
          "description": "Where to PUT the file's bytes.",
          "properties": {
            "href": { "type": "string", "format": "uri-reference" },
            "meta": {
              "type": "object",
              "properties": {
                "method": { "type": "string", "enum": ["PUT"], "example": "PUT" },
                "headers": { "type": "object", "additionalProperties": {}, "example": { "Content-Type": "image/png" } },
                "expiresAt": { "type": "string", "format": "date-time", "example": "2026-10-26T12:00:00+00:00" }
              },
              "required": ["method", "headers", "expiresAt"]
            }
          },
          "required": ["href", "meta"]
        },
        "complete": {
          "$ref": "#/components/schemas/RenderedLink",
          "description": "Present while the file is PENDING_UPLOAD."
        }
      }
    }
  ]
}
```

A declared link may not share its name with an action's link, the one
[`DeclaresActionLink`](#the-metadata-contract) names. The projection fails rather than
describing one of them wrongly.

A type whose resource declares [action links](links-and-meta.md#action-links) reports them
through the opt-in `DeclaresActionLinks` type metadata. Each names a resource-scope action
whose link the type's `<Type>ResourceLinks` then lists as a `RenderedLink`, whether or not the action's own
metadata names a link, and its `describedAs()` replaces the generic action-link
description. An action link naming no resource-scope action of the type on the document's
server is left out of it.

`LinkObject` itself allows every JSON:API 1.1 member: `href`, `rel`, `describedby` (a
URI-reference or a link object), `title`, `type`, `hreflang` (a string or a list of
strings) and `meta`. A server that [opts out](links-and-meta.md#built-links-are-link-objects)
to strings gets no `RenderedLink`; its members point at `LinkObject`, which then carries the
note about absolute `href`s, and the links in its operation examples are plain strings.

#### A `$ref` with an example is wrapped in `allOf`

Wherever a schema `$ref`s a component and also carries an `example`, `examples` or
`default`, the document writes the `$ref` as the single entry of an `allOf`, with the
other keywords beside it: `{"description": …, "allOf": [{"$ref": …}], "example": …}`. OAS
3.1 allows siblings next to a `$ref`, but tools that read OAS 3.0 semantics, ReDoc's
example sampler among them, ignore them and would show a sample of the bare component
instead. Today that covers an attribute, or a declared link's `meta` member, whose enum is
a named component when the field declares an example. A `$ref` with no such sibling is
written plainly. A code generator should read a single-entry `allOf` holding only a `$ref`
as that `$ref`.

### Operation examples

Every operation's request body and success responses carry a whole worked `example` on
their `application/vnd.api+json` media type, and each shared error response carries named
`examples`, one per code. The projector builds them from the examples you already declare:
the `example()` on each field, and the `exampleDetail`, `exampleSource` and meta members'
`example()` on each error descriptor. They follow the fields as they change and nobody
maintains a second copy. A code generator or an MCP tool
description can quote one as is.

The examples show what the server sends and accepts:

- A create or update request carries the members that write can set. A read-only field is
  left out and a write-only one is in. A create carries `id` only when the type's
  [id policy](resources.md) allows or requires a client id; an update always carries it.
  Each relationship the write can set is there too.
- A response resource carries what a read renders without a sparse fieldset: no write-only
  or sparse-by-default member. Its `id` is the `Id` field's example. Its `links`, and each
  relationship's `self` and `related`, are the URLs the server builds under the document's
  first server URL, in the server's link form, with their `describedby` when the server
  [describes its links](#links-and-describedby).
- A nested `Obj` or `Map` with no example of its own is built from its children's
  examples, and a `OneOf` from its first variant. That holds for a nullable or computed
  one too, since a `computedUsing()` or `extractUsing()` value still renders through the
  children; a `serializeUsing()` hook shapes the value itself, so that field needs its own
  example. A structured `ArrayList` shows one element built the same way from its `Obj`
  or `OneOf`. A scalar `ArrayList` or an `ArrayHash` has no children and needs its own
  example. A declared `null` example stays `null`.
- Linkage is built from the related type's `Id` example. Where the related type gives
  none, because the server does not register it or its `Id` declares no example, the
  relation's own [`exampleId()`](relations.md#example-ids-for-linkage) is used. A
  polymorphic relation shows its first related type. A relation to an unregistered type
  that declares no `exampleId()` shows its links and no `data` in a read, and is left out of
  a write; its relationship endpoints report the relation as missing an example.
- A collection holds one resource and shows page two of a larger set: the `self`, `first`,
  `prev` and `next` links its paginator renders, and its `meta.page`. A collection whose
  paginator always counts, which type or relation metadata reports through
  `DeclaresAlwaysCounted`, shows `last`, `meta.total` and `meta.page.total`, as every
  request gets them. A menu counts when its default strategy does, which is what its
  `wantsCount()` reports, and a cursor page never counts. Otherwise a countable collection
  on a server that registers the Countable profile shows what a `withCount=_self_` request
  gets, the parameter and the profile included. Any other collection shows the count-free
  page. An unpaginated collection carries `meta.total`,
  which the server always renders for one.
- A resource's [declared links](links-and-meta.md#declared-links) are in its `links`,
  each built from its `href` example and its meta members' examples. A declared link is
  part of what a read renders by default, the way an attribute is, so a link with no `href`
  example, or a rendered meta member with no example, is a miss like any other: the
  operation gets no example and the `MissingExample` names `links.<name>` or
  `links.<name>.meta.<member>`.
- A relationship endpoint carries the linkage document, and a relationship mutation the
  linkage it writes.
- A custom action's response is built from the type its `ActionResource` or `ActionCreated` names, and its
  request body from its input type when the input is a resource document. A `RawResult`
  keeps its own `example`.
- An error response carries one named example per code it offers, keyed by the code and
  referencing the code's entry in `components.examples`, summarised by its title. Each is a document holding that code's one error, with
  its `status`, `code`, `title`, `exampleDetail`, `source` and declared `meta`. A viewer shows
  the first entry by default, so the map leads with the code a reader most likely meets on
  the operations sharing the response: `QUERY_PARAM_MALFORMED` (else
  `REQUEST_BODY_INVALID_JSON_API`) for a `400`, `ACCESS_DENIED` for a `403`,
  `RESOURCE_NOT_FOUND` for a `404`, `RESOURCE_TYPE_UNACCEPTABLE` for a `409` and
  `ATTRIBUTE_VALUE_INVALID` for a `422`. The rest follow in catalogue order. A code whose
  example needs a value nothing declares, such as a `source` with no `exampleSource` or a
  required meta member with no `example()`, is left out of the map and reported.

Nothing is invented. When any value an operation's examples need has no declared example,
that operation gets none, and the projector says which field was missing:

```php
$projection = (new OpenApiProjector())->projectWithDiagnostics($metadata);

$projection->document;        // the OpenApi document
$projection->missingExamples; // list<MissingExample>
```

Each `MissingExample` names the `operation` (its `operationId`, or the response component
for an error response), the `type` and the `field`, such as `address.street` or
`tags.pivot.position`, and `message()` reads as a sentence. An export command can print
them as warnings, or fail on any under a strict flag. `project()` returns the document
alone.

To replace or switch off an operation's example, type metadata implements
`DeclaresOperationExamples` and returns a map keyed by operation name: `fetchCollection`,
`fetchOne`, `create`, `update`, `delete`, and for a relation `fetchRelated.<relation>`,
`fetchRelationship.<relation>`, `updateRelationship.<relation>`,
`addRelationship.<relation>` and `removeRelationship.<relation>`. A `false` or `null` value
switches that operation's example off. An `OperationExample` replaces the parts it names
and leaves the rest generated. Only the parts left generated need declared examples, so a
`MissingExample` is never reported for a part the override replaces or removes, and an
operation whose override covers every part reports nothing:

```php
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;

public function operationExamples(): array
{
    return [
        'delete' => new OperationExample(responses: [200 => ['meta' => ['deleted' => true]]]),
        'update' => false,
    ];
}
```

`request` takes the request document, or `false` for none. `responses` maps a success
status to its document, or to `false`. A name the type does not expose, or a status the
operation does not answer with a JSON:API document, fails the projection. These names are
the ones `OperationKey::fromName()` parses, so an integration that checks its own override
keys at boot can call it, or `OperationKey::tryFromName()` for `null` instead of a
`\InvalidArgumentException`. The key it returns carries the `OperationKind`, the relation
name and the HTTP method. Action metadata
implements `DeclaresActionExample` instead, whose `example()` returns one
`OperationExample`, `false`, or `null` to keep the generated one. Server metadata that
implements `DeclaresExampleGeneration` and returns `false` from `generatesExamples()`
switches generation off for the whole document.

## Operation ids

Every operation carries an `operationId` built from the type name, and from the relation or
action name where there is one:

| Operation | `operationId` |
|---|---|
| fetch collection, fetch one, create, update, delete | `fetchCollection.<type>`, `fetchOne.<type>`, `create.<type>`, `update.<type>`, `delete.<type>` |
| related read | `fetchRelated.<type>.<relation>` |
| relationship read, replace, add, remove | `fetchRelationship.<type>.<relation>`, `updateRelationship.…`, `addRelationship.…`, `removeRelationship.…` |
| custom action | `action.<type>.<path>`, or `action.<type>.<path>.<method>` when the action answers to more than one method |
| Atomic Operations | `atomic.operations` |

So the ids stay unique, even for a consumer that flattens them into names by dropping
case and punctuation, `ActionDeclarations` rejects with a `\LogicException`:

- one action path declared at both the resource and the collection scope, which would give
  both the same id;
- an action path that flattens to one of the words above (`ActionDeclarations::RESERVED_NAMES`),
  so `fetch-one` and `Delete` are refused. A relation's name is not reserved, so an `owner`
  action can sit beside an `owner` relation;
- two actions whose ids differ only in case or punctuation, such as `export-all` and
  `exportAll`.

The projector checks every type it projects. A framework integration should also call
`ActionDeclarations::validate($type, $actions)` when it registers a type's
actions, so the mistake fails at boot rather than when the document is first generated.

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

One optional contract sits beside the family: `ContributesErrorCodes` extends
`ServerMetadataInterface` with `errorSources(): iterable`, and the projector checks for it
when it assembles that server's [error catalogue](#the-error-code-catalogue). Metadata
that does not implement it publishes core's codes and nothing else.

`DeclaresMethodNotAllowed` is another. Its `rendersMethodNotAllowed(): bool` says whether the
adapter answers a method a path does not accept with a JSON:API error, which adds
`METHOD_NOT_ALLOWED` to the catalogue. No operation lists a `405` response, since an
operation is one method on one path.

A second sits beside `TypeMetadataInterface`: `DeclaresOperationExtensions` adds
`extensions(): array` and `operationExtensions(OperationType): array`, the vendor
extensions for a type's operations. `DeclaresNamedOperationExtensions` adds
`namedOperationExtensions(): array`, extensions for single operations keyed by operation
name. See
[your own extensions](#your-own-extensions-on-operations-paths-parameters-and-responses).

A third sits beside `RelationMetadataInterface`: `DeclaresWriteOnlyRelation` adds
`isWriteOnly(): bool`. A relation that reports `true` is projected the way a
[write-only attribute](fields.md#write-only-members) is. It stays in the create and update
request schemas, where its property carries `writeOnly: true`, and keeps its relationship `PATCH`/`POST`/`DELETE`, each documented as a
`204`. It is left out of the read resource schema, the `fields[type]` and `?withCount`
enums, and it has no related endpoint and no relationship `GET`. Report `true` only for an
unconditional `writeOnly()`; a relation write-only by predicate stays in the superset, as
an attribute does. Metadata that doesn't implement the interface projects every relation
as readable.

A fourth sits beside it: `DeclaresRelationUriFieldName` adds `uriFieldName(): string`, the
segment the relation's related and relationship endpoints are served under. Return the
core relation's `uriFieldName()`, which is the name unless `withUriFieldName()` set one.
Metadata that doesn't implement it keys every relation's paths by its name.

Another sits beside `RelationMetadataInterface`: `DeclaresPolymorphicRelation` adds
`isPolymorphic(): bool`. Return `true` when the core relation is a
`PolymorphicRelationInterface`, which `MorphTo` and `MorphToMany` are. Metadata that
doesn't implement it is polymorphic only when it lists more than one type. See
[polymorphic relations](#polymorphic-relations-with-one-type).

A fifth sits beside `TypeMetadataInterface`: `DeclaresSingularName` adds
`singularName(): ?string`, the noun the [generated prose](#how-generated-prose-names-a-type)
uses for one resource of the type. Mirror the resource's `getSingularName()`. Metadata that
doesn't implement it, or returns `null`, gets the inflected singular of the type name.

Three more shape the [operation examples](#operation-examples): `DeclaresOperationExamples`
beside `TypeMetadataInterface`, `DeclaresActionExample` beside `ActionMetadataInterface`, and
`DeclaresExampleGeneration` beside `ServerMetadataInterface`. Metadata that implements none
of them gets every example the projector can build.

Two more feed the examples facts the rest of the contract does not carry.
`DeclaresAlwaysCounted` adds `alwaysCounts(): bool` to type or relation metadata: return the
collection paginator's `wantsCount()`. `DeclaresRelationExampleId` extends
`RelationMetadataInterface` with `exampleId(): ?string`: return the core relation's
`exampleId()`.

Four more describe links. `DeclaresLinkObjects` extends `ServerMetadataInterface` with
`rendersLinkObjects(): bool` (mirror `Server::withLinkObjects()`) and
`documentUrl(): ?string`, the URI-reference the document is served at, which every
`describedby` points into. Metadata that doesn't implement it projects link objects without
`describedby`, which is what a server renders by default. `DeclaresActionLink` extends
`ActionMetadataInterface` with `linkName(): ?string`: a resource-scope action that returns a
name is listed under it in its type's `<Type>ResourceLinks`, described by its first declared
method. `DeclaresResourceLinks` extends `TypeMetadataInterface` with
`declaredLinks(): list<DeclaredLink>`: return the resource's `allLinks()`. Metadata that
doesn't implement it projects no declared links. `DeclaresActionLinks` extends
`TypeMetadataInterface` with `actionLinks(): list<ActionLink>`: return the resource's
`allActionLinks()`. Metadata that doesn't implement it projects only the action links
`DeclaresActionLink` names.

Two more sit beside `ActionMetadataInterface`. `DeclaresActionSafety` adds `readOnly()`,
`destructive()` and `idempotent()`, each `?bool`, which project as
[`x-safety`](#action-safety-x-safety). `DeclaresActionExtensions` adds `extensions(): array`,
the action's own vendor extensions. Action metadata that implements neither projects as
before.

`DeclaresActionFilters` extends `ActionMetadataInterface` with `filters(): bool|array`,
the [filters a collection action takes](#filters-on-a-collection-action) from its type:
`true` for all of them, a list of filter keys, or `false` for none.

Three more carry [deprecation](#deprecation). `DeclaresDeprecation` extends
`TypeMetadataInterface` with `isDeprecated(): bool`, `sunset(): ?\DateTimeInterface` and
`deprecatedOperations(): list<string>`; fill it from the declaration behind the type's
`Deprecation` and `Sunset` response headers. `DeclaresActionDeprecation` extends
`ActionMetadataInterface` with `isDeprecated(): bool`. `DeclaresDeprecatedRelation` extends
`RelationMetadataInterface` with `isDeprecated(): bool`: return the relation field's
`isDeprecated()`. Metadata that implements none of them projects no deprecation markers.

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
asynchronous `202`, or a `303` completion enters the contract.

### The response objects

Each object names one HTTP status an operation may return; a declaration is a **list** of
them (a set). They are typed per operation so an illegal combination is unrepresentable:

| Object | Status | Body / headers | Valid on |
|---|---|---|---|
| `new Created()` | `201` | created resource document + `Location` | create |
| `new Ok()` | `200` | resource / collection document | update, fetch-one, fetch-collection |
| `new NoContent()` | `204` | no body | create, update, delete, actions |
| `new Accepted(string $jobType)` | `202` | the **job** type's document + `Content-Location` + `Retry-After` | create, update, actions |
| `new SeeOther(?string $description, ?string $mediaType)` | `303` | `Location` only, no body; on an action with a `mediaType` also a `200` [location document](#actions-that-answer-with-a-url) | fetch-one, actions |
| `new MetaResult()` | `200` | a meta-only document | delete, actions |
| `new ActionResource(string $type)` | `200` | the named type's document | actions |
| `new ActionCreated(string $type)` | `201` | the named type's document + optional `Location` | actions |
| `new RawResult(string $mediaType, …)` | `200` by default | a body that is not JSON:API, beside the JSON:API one | actions |

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

### Raw action responses

An action that answers with something other than a JSON:API document, such as a CSV
export, a PDF or an image, declares a `RawResult` per media type. Several sit beside the
action's JSON:API response at the same status, and the request's `Accept` header picks
between them:

```php
use haddowg\JsonApi\OpenApi\Metadata\{MetaResult, RawResult};

$responds = [
    new MetaResult(),
    new RawResult('text/csv', schema: ['type' => 'string'], headers: [
        'Content-Disposition' => 'Names the downloaded file.',
    ], example: "id,title\n1,Hello\n"),
    new RawResult('application/pdf', binary: true),
    new RawResult('application/json', schema: ChartSummary::class),
];
```

`schema` is an inline JSON Schema array, or the class name of a `DescribesRawBody`, whose
static `jsonSchema()` the projector emits once as `#/components/schemas/<ShortClassName>`
and `$ref`s from each use. A class whose short name another component already holds is
refused. `binary: true` projects `{type: string, format: binary}`. `headers` maps a header
name to its description, or to a `Header` object, and joins the response's headers.
`example` becomes the media type's example. Each media type lands under the status's
`content`, next to `application/vnd.api+json` when the action declares a JSON:API response
there too. `ActionResponses::validate()` allows any number of raw results per status, one
per media type; the media type must be concrete, never JSON:API, and the status a `2xx`
with a body.

At runtime the integration picks the media type with
`Negotiation\AcceptNegotiator::negotiate($accept, ActionResponses::mediaTypes($responds))`.
Each media type takes the `q` of the most specific `Accept` range that matches it, the
highest wins, and a tie goes to JSON:API, so a client that accepts JSON:API as readily as
anything else gets it. Nothing acceptable is a `406` `MediaTypeUnacceptable`, and every
error renders as a JSON:API document whatever was negotiated. A handler answers a raw pick
with a [`RawResponse`](responses.md#raw-responses).

A document whose actions declare no `RawResult` is byte-for-byte unchanged.

### Actions that create a resource

An action that creates the resource it answers with, such as
`POST /orders/{id}/-actions/duplicate`, declares **`new ActionCreated('orders')`**. The
projection documents a `201` whose body is the named type's document, with an optional
`Location` header, and offers the type's `include` and `fields[<type>]` parameters, as an
`ActionResource` does. It can sit beside a `200` `ActionResource` for an action that
sometimes creates and sometimes returns an existing resource.

The handler returns a `DataResponse` as usual. `Server::dispatch()` renders it as `201`
when the action declares an `ActionCreated` and no `ActionResource`
(`ActionResponses::dataStatus()`), so the declared status and the sent one cannot drift.
When the action declares both, the response stays `200` unless the handler calls
`withStatus(201)`, and a status the handler set always wins. Setting the `Location` header
is the handler's choice, with `withHeader('Location', …)`.

### Actions that answer with a URL

An action that hands out a URL, such as a short-lived download link, declares `SeeOther`
with a `mediaType`, the media type the URL serves. A download whose type isn't known
declares `application/octet-stream`. `description` replaces the generated wording. The
`mediaType` is the opt-in: a bare `new SeeOther()`, or one with only a description, stays
the plain `303` it always was, at runtime and in the projection.

```php
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;

$responds = [new SeeOther('A short-lived link to the export.', 'text/csv')];
```

The handler returns `SeeOtherResponse::to($url, $expiresAt)` (see
[location responses](responses.md#location-responses)), and the request's `Accept` decides
the shape. A request that names `application/vnd.api+json`, with nothing else it lists
weighing more, gets a `200` location document. Any other request gets the `303`: no
`Accept`, `*/*`, a browser's navigation, a link click or `curl`. The document exists
because a browser cannot read a `303`'s `Location` from `fetch()`, which with
`redirect: 'manual'` returns an opaque response:

```json
{
  "links": {
    "self": { "href": "https://api.example.com/files/1/-actions/download" },
    "location": {
      "href": "https://storage.example.com/1.csv?signature=abc",
      "type": "text/csv",
      "meta": { "expiresAt": "2026-10-27T12:00:00+00:00" }
    }
  },
  "meta": { "expiresAt": "2026-10-27T12:00:00+00:00" },
  "jsonapi": { "version": "1.1" }
}
```

`self` is the action's own URL. `location.type` is the declared media type unless the
handler sets its own with `withType()`, and `location.meta` appears when the URL expires. JSON:API needs a
`meta`, `data` or `errors` member at the top level, so the top-level `meta.expiresAt` is
always there, `null` for a URL that does not expire. The expiry is written in the
server's output timezone, as a `DateTime` attribute is. Both shapes send
`Cache-Control: no-store` and `Vary: Accept` unless the handler set its own. Errors stay
JSON:API documents.

The projection documents both responses. The `200` references a shared
`LocationDocument` component, whose `links.location` is a `LocationLink` with `href`,
`type` and `meta.expiresAt`, narrowed in an `allOf` so `type` is a `const` of the declared
media type. The `303` documents `Location` as a `uri-reference`, and both
document `Cache-Control`. When the action also declares a `200` JSON:API body, such as an
`ActionResource`, the `200` is a `oneOf` of that document and the location document. The
operation's example is the location document with a fixed example URL and expiry, typed
with the declared media type.

An integration negotiates such an action with
`AcceptNegotiator::negotiateAction($accept, $responds)`, which returns `null` instead of
throwing a `406` when no declared media type is acceptable, because the action can still
answer with its `303`. An action with a bare `SeeOther` keeps the `406`, since it may
answer with a JSON:API body instead. It passes the declared responses to `CustomActionOperation`
(`responds:`), so `Server::dispatch()` can type the location link from the declared
media type. `SeeOther` on a fetch-one is unchanged: always the bare `303`.

### Accepted request bodies

An action's input mode projects one request body: none, its JSON:API document, or an
open `application/octet-stream` body for raw input. An action that takes several media
types, or a raw body with a known shape, also implements `DeclaresAcceptedBodies` on its
metadata, and each `AcceptedBody` becomes one `requestBody.content` entry:

```php
use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;

public function accepts(): array
{
    return [
        new AcceptedBody('text/csv', schema: ['type' => 'string']),
        new AcceptedBody('image/*', binary: true),
        new AcceptedBody('multipart/form-data'),
        new AcceptedBody('application/json', schema: ImportRows::class),
    ];
}
```

The media type may end in a wildcard, such as `image/*`, and parameters are dropped from
the key. A JSON:API entry, `application/vnd.api+json` by default or any entry declared with
`document: true`, carries the action's request document. `binary: true` gives a binary
string, `schema` takes an inline array or a `DescribesRawBody` class emitted as a component
the way a raw response's is. An entry with none of them takes a default from its media
type: a string for `text/*`, any JSON value (`{}`) for `application/json` and any `+json`
type, an open object for `multipart/form-data` and `application/x-www-form-urlencoded`,
and a binary string for anything else, such as `image/*` or `application/pdf`. A wildcard
follows its family, so `text/*` is a string. The body is required only when the
action's input mode is `Document`, and the operation lists the `415` a `Content-Type` it
does not accept gets.

An action that returns no accepted bodies, or whose metadata does not implement the
interface, projects as before.

### Filters on a collection action

A collection-scope action, such as `POST /articles/-actions/archive`, often works on the
same rows a collection fetch would list. Its metadata can implement `DeclaresActionFilters`
to take the mount type's declared filters, so a client narrows the rows with the
`filter[<key>]` parameters it already uses on `GET /articles`. `filters()` returns `true`
for every filter the type declares, a list of filter keys for some of them, or `false` for
none:

```php
public function filters(): bool|array
{
    return ['status', 'publishedAt'];
}
```

The action's operation lists one `filter[<key>]` parameter per filter it takes, in the order
the type declares them, built the same way the collection fetch builds them. A `Range` keeps
its `deepObject` style, a set filter its delimited array, and a declared example, description
or deprecation comes along unchanged. The operation already lists the `400` an invalid
filter value gets.

Only a collection-scope action may take filters, and every key in the list must be one the
type declares. `ActionDeclarations::validate()` rejects either mistake with a
`\LogicException` naming the action and the type, and the projector runs the same check.
`ActionDeclarations::filters()` resolves the filters an action takes from its type's
metadata. An adapter parses, validates and applies exactly that list, the way it does for
the collection fetch, and rejects any other `filter[<key>]` with the same `400`.

Such an action also needs the type's `fetchCollection` scopes beside its own. The
[scope table](scopes.md) adds them, so the operation's `security`, the enforcer and the
operation catalogue all list the same union.

A document with at least one action taking a filter declares the `ACTION_FILTERS` contract
tier in its [`x-generator`](#the-generator-contract-x-generator) stamp. Action metadata that
does not implement the interface, or returns `false`, projects as before.

### The asynchronous-write lifecycle

Together the objects reflect the JSON:API
[asynchronous processing](https://jsonapi.org/recommendations/#asynchronous-processing)
recommendation in the generated document:

- A write accepted for background processing advertises **`new Accepted('jobs')`** — a
  `202` whose body is the `jobs` type's document, plus a `Content-Location` header (the
  job URL to poll) and a `Retry-After` hint. Register the job type so the body references
  its `JobsDocument` component; for a type the server doesn't describe, the projector
  inlines an open document whose `data.type` is the job type instead of naming a
  component it never defines. An `ActionResource` or `ActionCreated` body type works the same way.
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

Filters take both too. A filter's `describedAs()` overrides the generated default every
convenience filter ships with, and its `example()` becomes the `filter[<key>]` parameter's
own `example`. That puts it on the Parameter Object rather than the value schema, because
OAS 3.1 deprecates the Schema Object's `example`. Give it the
parameter's whole value, in the shape the schema describes:

```php
Where::make('name')->describedAs('Match the artist name.')->example('Radiohead'),
WhereIn::make('genre')->example(['rock', 'jazz']),
Range::make('rating')->example(['min' => 3, 'max' => 5]),
```

The projector emits the example as declared and does not check it against the schema. A
filter without one is unchanged, and a custom filter opts in by implementing
`ExemplifiedFilter`.

[Relations](relations.md) take `describedAs()` as well. Beyond fields, every documentable element
gets a **generated default description** the author may override declaratively: a type's
resource object via its `description()`, and each CRUD / related / relationship operation
via a per-operation description override — the projector emits the author's value when
present, a short generated sentence otherwise, never a blank. Because a request-time,
per-caller condition cannot be baked into a cached schema, a *conditionally* hidden or
prohibited field/verb is documented as the **superset** — the union of what any caller
may see or do (see the [request-aware predicates](fields.md) note).

### How generated prose names a type

A generated summary or description that means one resource names it in the singular, with
its article: `GET /advertisers/{id}` reads "Fetch an advertiser", and its related endpoint
"Fetch the related agencies of an advertiser". A collection keeps the plural: "List
advertisers". Wording that names the type itself, such as "a resource object of type
`advertisers`", keeps the type name as declared.

The type name renders as lower-case words, split at `-`, `_` and camelCase boundaries, so
`export-jobs` and `exportJobs` both read "export jobs" and "an export job". An all-caps
word keeps its case. The singular inflects the last word with `doctrine/inflector`, which
handles the common irregulars (`people` reads "person").

The article is "an" before a vowel letter and "a" otherwise, with three exceptions: a word
that starts with a consonant sound takes "a" ("a user", "a unique code", "a one-off
charge"), a silent `h` takes "an" ("an hour"), and an all-caps acronym follows the spoken
name of its first letter ("an SDK release", "a URL alias").

When the inflection is wrong or reads badly, override `getSingularName()` on the resource:

```php
final class MediaResource extends AbstractResource
{
    protected static string $type = 'media';

    public function getSingularName(): ?string
    {
        return 'media item';
    }
}
```

Return the noun as words, without an article; the projector picks "a" or "an" from it. The
framework integration reads it through `DeclaresSingularName`.

Generated parameter descriptions also leave out what the parameter's schema already says.
The allowed `sort` tokens, `include` paths, `fields[TYPE]` members and `withCount` tokens
are each the `items` enum, so the prose explains the encoding (a comma-separated list, `-`
for descending, the profile that must be negotiated, where a count lands) and stops there.
A backed enum's `value → description` table stays, because it carries what each value
means, which an `enum` cannot.

## Deprecation

A type, a single operation, an action, a field, a relation, a filter and a sort can each be
marked deprecated. Every marker is documentation only. The server keeps serving whatever is
marked exactly as before; the document just tells clients to move off it.

A **type** whose metadata implements `DeclaresDeprecation` and reports `isDeprecated()`
marks every operation the document emits for it `deprecated: true`: its CRUD operations,
its related and relationship endpoints and its custom actions. A declared `sunset()` adds a
sentence naming the date to the end of each of those operations' descriptions, after any
description an author declared:

```json
"/articles": {
  "get": {
    "description": "…\n\nThis operation may stop responding on or after 2027-06-30, its sunset date.",
    "deprecated": true
  }
}
```

A sunset is a date rather than a flag, so it marks nothing deprecated on its own.

**Single operations** are listed by name in `deprecatedOperations()`, in the grammar
[operation examples](#operation-examples) and scopes are keyed by: `fetchCollection`,
`fetchOne`, `create`, `update`, `delete`, or `fetchRelated`, `fetchRelationship`,
`updateRelationship`, `addRelationship` or `removeRelationship` with a dot and the relation
name. A name the type does not expose fails the projection with an
`\InvalidArgumentException`. `OperationKey::tryFromName()` checks a name earlier. An
**action** is not named there; its metadata implements `DeclaresActionDeprecation`
instead.

A **field** declared [`deprecated()`](fields.md#deprecation-deprecated) carries
`deprecated: true` on its property in the read, create and update attribute schemas, at
whatever depth it sits. A **relation** carries it on its property under `relationships` in
the read resource and the write requests, beside the `$ref`:

```json
"relationships": {
  "type": "object",
  "properties": {
    "author": { "$ref": "#/components/schemas/ArticlesAuthorRelationship", "deprecated": true }
  }
}
```

A **filter** marks its `filter[<key>]` parameter `deprecated: true`. A **sort** cannot be
marked inside the `sort` enum, so its key is listed under
[`x-deprecated-sorts`](#deprecated-sorts-x-deprecated-sorts), and a deprecated sort family's
entry in [`x-sort-families`](#sort-families-x-sort-families) carries `deprecated: true`.

A document that carries any of these markers declares the `DEPRECATION` contract tier in
its [`x-generator`](#the-generator-contract-x-generator) stamp, so an older generator is
warned that it may drop them. A document with none of them, including one whose only
declaration is a sunset, keeps the lower stamp.

## Vendor extensions

The projection emits a few OpenAPI **vendor extensions** (`x-…` keywords) to carry
information the base OAS vocabulary cannot.

### The generator contract: `x-generator`

Every projected document stamps its `info` with one integer:

```json
"info": {
  "title": "Music API",
  "version": "1.2.0",
  "x-generator": { "contract": 22 }
}
```

`contract` is the version of the **emitted structure**, for the code generators that read
the document ([`json-api-client-codegen`](https://github.com/haddowg/json-api-client-codegen)
and [`@haddowg/json-api-codegen`](https://github.com/haddowg/json-api-ts)). It is not the
package version and moves only when the structure does. A generator declares the range it
understands: it **errors** on a document below that range and **warns** on one above it,
since a newer server describes capabilities the generator would otherwise silently leave
out. A document with no `x-generator` predates the field and reads as contract 1. What
each release added is in the [changelog](https://github.com/haddowg/json-api/blob/main/CHANGELOG.md).

#### Diffing documents across releases

A `$ref` that carries an `example` or `default` is written as a single-entry `allOf`
holding the `$ref`, with the example beside it. That means the same schema, but
`oasdiff breaking` compares the `allOf` wrapper with a bare `$ref` and reports false
"required property removed" findings against a document from an older release. Pass
`--flatten-allof` so oasdiff merges each `allOf` before comparing:

```bash
oasdiff breaking --flatten-allof old.json new.json
```

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

The description also says where each requested total lands. On an endpoint that accepts
`_self_` it reads "Request `withCount=_self_` to receive the collection total in the
top-level `meta.total` (also in `meta.page.total` when the collection is paginated)". On
one that accepts relation tokens it names the first, e.g. "Request a relation name, e.g.
`withCount=comments`, to receive that relation's total on each resource in
`relationships.comments.meta.total`".

### Sort families: `x-sort-families`

A [sort family](sorts.md#sort-families-keys-matched-by-prefix) accepts keys that
can't be listed, so its keys can't join the `sort` enum. When an endpoint's sort
vocabulary has a family, the `sort` array's `items` become an `anyOf`: the enum of
fixed keys and their `-` forms, then one string `pattern` per family. The pattern
is `^-?`, the escaped prefix, a dot and the family's suffix pattern. The array
schema also lists each family under `x-sort-families`, so a generator can offer a
typed helper rather than parse the pattern back:

```json
"schema": {
  "type": "array",
  "items": {
    "anyOf": [
      { "type": "string", "enum": ["title", "-title"] },
      { "type": "string", "pattern": "^-?value\\.(?:[a-z_]+)$" }
    ]
  },
  "x-sort-families": [
    { "prefix": "value", "suffixPattern": "[a-z_]+" }
  ]
}
```

An endpoint with no family keeps the plain enum, and a document whose endpoints
declare none carries no `x-sort-families` and keeps the lower contract stamp.

A [deprecated](sorts.md#deprecating-a-sort) family's entry adds `"deprecated": true` beside
its `prefix` and `suffixPattern`. The flag sits in the family's own entry so a prefix is
never mistaken for an exact key.

### Deprecated sorts: `x-deprecated-sorts`

The `sort` array schema lists the exact keys declared [deprecated](sorts.md#deprecating-a-sort)
under `x-deprecated-sorts`, without the `-`. Each key stays in the `items` enum in both
directions, because a deprecated sort still works:

```json
"schema": {
  "type": "array",
  "items": { "type": "string", "enum": ["name", "-name", "rank", "-rank"] },
  "x-deprecated-sorts": ["name"]
}
```

A `sort` parameter with no deprecated key carries no `x-deprecated-sorts`, and a deprecated
family is flagged in `x-sort-families` rather than listed here.

### Action safety: `x-safety`

A custom action's method doesn't say whether it changes anything. A search sent as `POST`
reads like a write. Action metadata that implements `DeclaresActionSafety` declares it
instead, through three methods that each return `?bool`: `readOnly()`, `destructive()` and
`idempotent()`. The projector stamps the declared flags on the action's operation, one
object per operation, and leaves out any flag that returns `null`:

```json
"/files/{id}/-actions/purge": {
  "post": {
    "operationId": "action.files.purge",
    "x-safety": { "destructive": true, "idempotent": true }
  }
}
```

An action that declares none of the three gets no `x-safety`, and CRUD operations never
get one. An action declared both read-only and destructive fails with a `\LogicException`.

### Pagination strategy: `x-paginator`

Every key a paginator reads can be renamed (`PagePaginator::make()->withPageKey('p')`), and
so can its `kind()`. Key names alone therefore can't tell a client which strategy a
collection uses. Each built-in paginator stamps its page schema with the strategy and the
key that fills each of its roles:

```json
"schema": {
  "type": "object",
  "properties": { "p": { "type": "integer" }, "size": { "type": "integer" } },
  "x-paginator": { "strategy": "page", "keys": { "number": "p", "size": "size" } }
}
```

| `strategy` | `keys` roles |
| --- | --- |
| `page` | `number`, `size` |
| `offset` | `offset`, `limit` |
| `cursor` | `after`, `before`, `size` |
| `fixed` | `number` |

A [`MultiPaginator`](pagination.md#offering-a-menu-of-strategies) menu stamps its `oneOf` with
`{"strategy": "multi", "default": "<kind>"}`, where `default` is the `kind` the server
picks when a request names none. Each arm carries its child's own `x-paginator` beside the
`kind` const that selects it. A custom paginator that stamps nothing is left to whatever a
generator infers from its key names.

Each built-in member also carries the paginator's `default` and, for the page size, its cap
as `maximum`. See [In OpenAPI](pagination.md#in-openapi) on the pagination page.

### Your own extensions on operations, paths, parameters and responses

`Operation`, `PathItem`, `Parameter` and `Response` each carry a map of vendor extensions,
emitted after their standard members. `withExtension()` sets one, `withExtensions()`
merges several, and `extension()` reads one back. A name without the prefix gets it, the
way `Schema::withExtension()` does it, so `withExtension('feature', 'agency')` emits
`x-feature: agency`. A map passed to a constructor's `extensions:` argument must already
use `x-` keys. The `x-oai-` and `x-oas-` prefixes are reserved by the OpenAPI Initiative,
and both forms throw an `\InvalidArgumentException` for them.
`extensions()` returns the whole map, keyed `x-…`. `Info` and `Schema` have it too.

A document decorator can tag a feature-gated operation directly:

```php
$item = $document->paths->items['/agencies'];
$item = $item->withOperation('get', $item->operations['get']->withExtension('feature', 'agency'));
$document = $document->withPaths($document->paths->with('/agencies', $item));
```

To declare them on the type instead, have its metadata implement
`DeclaresOperationExtensions`. The projector stamps `extensions()` on every operation it
emits for the type: the CRUD operations, its related and relationship endpoints, and its
custom actions. It then merges `operationExtensions($operation)` over that for each CRUD
operation, so the operation's value wins on a shared key. Metadata that doesn't implement
the interface projects as before. A framework integration builds the two maps from its
own resource configuration.

To target one operation that is not CRUD, such as a single related endpoint, implement
`DeclaresNamedOperationExtensions` as well or instead. Its `namedOperationExtensions()`
returns a map keyed by operation name, the same names
[`operationExamples()`](#operation-examples) and `operationScopes()` use: `fetchCollection`,
`fetchOne`, `create`, `update`, `delete`, or `fetchRelated`, `fetchRelationship`,
`updateRelationship`, `addRelationship` or `removeRelationship` followed by a dot and the
relation name.

```php
public function namedOperationExtensions(): array
{
    return [
        'fetchRelated.agencies' => ['feature' => 'agency'],
        'fetchRelationship.agencies' => ['feature' => 'agency'],
    ];
}
```

The projector layers the sets for each operation in this order, a later one winning on a
shared key: the type-wide `extensions()`, then `operationExtensions()` for a CRUD
operation, then the set named for the operation. Every layer is normalised to `x-` keys
before the merge, so `feature` and `x-feature` are the same key. A name the type does not
expose fails the projection with an `\InvalidArgumentException`. Actions have no name in
this map, since they declare their own as below. `OperationKey::tryFromName()` parses a
name, so an integration can check its configuration before it projects.

An action declares its own with action metadata that implements `DeclaresActionExtensions`,
whose `extensions()` returns the map. It lands on that action's operation only, merged over
the type-wide set, so the action's value wins on a shared key. A link to an
[Arazzo](https://spec.openapis.org/arazzo/latest.html) workflow is the usual case:

```php
public function extensions(): array
{
    return ['workflows' => ['source' => 'workflows.arazzo.yaml', 'workflowId' => 'purgeFile']];
}
```

A declared [`x-safety`](#action-safety-x-safety) wins over an extension of the same name.

### Changing one member without losing the rest

Change a generated value object through its withers rather than rebuilding it with
`new`. Every member of `Operation`, `PathItem`, `Response` and `Parameter` has a
`with<Member>()` wither (`withDescription()`, `withResponses()`, `withParameters()`,
`withDeprecated()` and so on), and each returns a copy with every other member intact,
extensions included. A `new Operation(...)` copies only the arguments you pass, so it drops
whatever an earlier decorator set.

The collection members also take one item at a time:

| Value object | Single-item wither | Replaces |
|---|---|---|
| `Operation` | `withResponse($status, $response)` | the response for that status |
| `Operation`, `PathItem` | `withParameter($parameter)` | the parameter with the same `name` and `in`, in place (a `Reference` is appended) |
| `PathItem` | `withOperation($method, $operation)` | the operation for that method |
| `Response`, `RequestBody` | `withMediaType($mediaType, $media)` | that media type's body |
| `Response` | `withHeader($name, $header)` | that header |
| `Components` | `withSchema()`, `withResponse()`, `withParameter()`, `withRequestBody()`, `withHeader()`, `withSecurityScheme()` | the component with that name |
| `OpenApi` | `withPath($path, $item)` | that path |

A decorator that documents rate limiting on an operation another decorator already tagged:

```php
$item = $document->paths->items['/agencies'];
$operation = $item->operations['get']
    ->withResponse('429', Reference::to('responses', 'TooManyRequests'))
    ->withDescription('Limited to 60 requests a minute.');

$document = $document
    ->withPath('/agencies', $item->withOperation('get', $operation))
    ->withComponents($document->components->withResponse('TooManyRequests', new Response('Too Many Requests')));
```

`x-feature` survives both steps. `Info`, `MediaType`, `RequestBody`, `Tag`, `Server` and
the `OpenApi` document carry withers for the members a decorator usually changes.

## Serving a decorated document (for adapter authors)

An adapter that lets applications change the served document per request (a `servers`
entry per host, operations hidden for one tenant) builds on four classes in
`haddowg\JsonApi\OpenApi\Serve`. They take strings and `stdClass` trees and know nothing
about HTTP messages, so the decorator interfaces, the controller and the cache location
stay in the adapter. Using them keeps every adapter pruning and tagging the same way.

At warm-up, wrap the built document in a `ServeArtifact` and encode it beside the JSON:

```php
$artifact = ServeArtifact::of($document);
$codec = new ServeArtifactCodec(['acme/json-api-adapter']);

file_put_contents("$dir/default.json", $json = $document->toJsonString(true));
file_put_contents("$dir/default.hash", EntityTag::hash($json));
file_put_contents("$dir/default.serve", $codec->encode($artifact));
```

On a request, decode it, run the decorators, then prune:

```php
$artifact = $codec->decode($bytes) ?? ServeArtifact::of($factory->build());
$document = $artifact->document;
foreach ($decorators as $decorator) {
    $document = $decorator->decorate($document, $request);
}
$body = json_encode($artifact->prune($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
```

`decode()` returns `null` when the bytes are unreadable or were written by another
installed version of this package or of a package named in the codec's first argument,
so stale artifacts left behind by a deploy fall back to a fresh build. Decoding allows
only the OpenAPI value objects, `stdClass` and enums. If an `example()`, a default or an
extension value holds another object, `encode()` throws `UnserializableValue` unless its
class is passed in the second argument, `allowedClasses`. It's a `LogicException` whose
`$class` names the rejected class, so an adapter can report it without parsing the message.

`prune()` drops what the decorators orphaned: path items with no operations left, tags no
remaining operation uses, and components the undecorated paths reached that nothing
reaches now, following `$ref`s and discriminator mappings. A component the undecorated
document never referenced, or one a decorator added, is kept along with everything it
references. Security schemes are never pruned. A relationship whose related type lost
its endpoints keeps its schema, since the parent still renders that linkage.

`EntityTag` builds the `ETag`:

- `EntityTag::hash($bytes)` for the stored artifact when no decorator runs, or for the
  final body.
- `EntityTag::keyed($artifactHash, $keys)` when every decorator can give a cache key
  for the request. `$keys` lists `[decorator class, key]` pairs in the order the
  decorators run, and the tag can be checked before anything is decoded. If any
  decorator has no key, fall back to the body hash, because a partial key would let two
  different bodies share a tag.
- `EntityTag::matches($etag, $ifNoneMatch)` compares against the raw `If-None-Match`
  header value. It matches weakly, so a `W/` tag from a compressing CDN still matches,
  and `*` matches anything.

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
