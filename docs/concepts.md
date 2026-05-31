# Concepts

This page explains the JSON:API document model as `haddowg/json-api` represents
it, and pins down the vocabulary the rest of the documentation relies on. It is
conceptual: it describes the structures the spec defines and where each lives in
the codebase, but it is not a how-to. For building responses see
[Responses](responses.md); for declaring a resource type see [Resources](resources.md).

The [JSON:API 1.1 specification](https://jsonapi.org/format/1.1/) defines the
shape of every message exchanged with the API. The library models those shapes as
a layered set of value objects. Most are `@internal` — the serialization engine
builds them for you and you never construct one — but understanding the model
makes the consumer-facing surface (Resource classes, response value objects) much easier to
reason about.

## Vocabulary

The word "resource" is overloaded across the spec and this library. Three
distinct things wear the name, and the documentation keeps them apart:

- **Resource object** — the spec structure `{type, id, attributes, relationships}`
  that appears inside a document's `data` (and inside `included`). This is "a
  resource" in the spec sense. The engine produces it as a plain array from your
  Resource class; it is not a class you instantiate (there is no `ResourceObject`
  class).
- **Resource class** — a [`Resource\AbstractResource`](resources.md) subclass. This
  is a *per-type serializer + hydrator*: one `fields()` declaration that tells the
  engine how to turn a domain object into a resource object and how to fill a
  domain object from a request. It is the recommended surface you write to describe
  a JSON:API resource type.
- **Serializer / Hydrator** — the lower-level `Serializer\SerializerInterface` /
  `Hydrator\HydratorInterface` contracts a Resource class satisfies. Either is
  usable directly for full control when the field walk isn't enough; see
  [Custom serializers](serializers.md).

So: a *Resource class* and a *serializer* both produce *resource objects*. The 95%
path is to write a Resource class and never touch the other two terms.

## Documents

A JSON:API **document** is the top-level JSON object of every request and response
body. Per the spec it carries at most one of `data` or `errors`, plus optional
`meta`, `links`, `jsonapi`, and `included` members. The library models documents
under `Schema\Document\*` — `SingleResourceDocument`, `CollectionDocument`,
`MetaDocument`, and `ErrorDocument` — behind the `@internal`
`Schema\Document\DocumentInterface`.

> **You never write a document subclass.** Documents are internal, mutable,
> per-render machinery. Consumers return one of the [response value
> objects](responses.md) (`DataResponse`, `MetaResponse`, `ErrorResponse`,
> `RelatedResponse`, `IdentifierResponse`); each builds the appropriate internal
> document during rendering. The document layer is documented here only so the
> output structure is legible.

Every document shares three optional top-level members, captured by
`DocumentInterface`: the [`jsonapi` object](#the-jsonapi-object), `meta`, and
`links`. The response value objects expose these through their `withJsonApi()`,
`withMeta()`, and `withLinks()` withers.

## Resource objects

A resource object is the `{type, id, attributes, relationships}` structure inside
`data`. The library builds it field-by-field from your [Resource class](resources.md): the
`Id` field becomes the top-level `id`, attribute fields become `attributes`, and
relationship fields become `relationships`. The `type` member is the Resource class's
static `$type`.

```json
{
    "type": "articles",
    "id": "1",
    "attributes": { "title": "JSON:API in PHP" },
    "relationships": {
        "author": { "data": { "type": "people", "id": "9" } }
    }
}
```

Sparse fieldsets (`?fields[articles]=title`) and inclusion (`?include=author`) are
applied by the engine as it walks the Resource class — the Resource class emits every
eligible field and the engine narrows the output to match the request. See
[Resources](resources.md#how-fields-drive-serialization).

## Resource identifiers

A **resource identifier** references a resource without carrying its full
representation: just `{type, id}` (plus optional `meta`). It is the `data` of a
relationship and the contents of a `relationships/…` linkage endpoint. In code it
is `Schema\ResourceIdentifier` — a `final readonly` value object with public
`type`, `id`, `lid`, and `meta` properties.

```php
final readonly class ResourceIdentifier
{
    public function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $lid = null,
        public array $meta = [],
    ) {}
}
```

### Local ids (`lid`)

JSON:API 1.1 lets a client reference a resource that does not yet exist by a
document-local id, `lid`, in place of `id`. `ResourceIdentifier` models both as
nullable and `fromArray()` requires `type` plus at least one of `id`/`lid` —
otherwise it throws the typed `ResourceIdentifierIdMissing`. A `lid` is a local
handle, never the resource's identity: a resource created with a `lid` still
receives a server-generated `id`, and the request exposes the supplied `lid`
separately.

> Resolving a `lid` to a freshly-created resource within one request is not
> supported. A `lid` parses, validates, and flows through to the hydrator, but
> the library does not wire it back to a created resource for you.

## Relationships

A **relationship** connects one resource to others. The spec distinguishes
*to-one* and *to-many* relationships; the data is a single resource identifier (or
`null`) for to-one and a list of resource identifiers for to-many. The library has
two distinct relationship type families because input and output have different
needs:

- **Output (serialization) side** — `Schema\Relationship\ToOneRelationship` /
  `ToManyRelationship` (over `AbstractRelationship`). These are *mutable builders*
  a serializer constructs to describe a resource's relationships, with fluent
  `setData()`/`setLinks()`/`setMeta()`/`omitDataWhenNotIncluded()`. They own the
  inclusion and deduplication logic that drives compound documents (`included`).
- **Input (hydration) side** — `Hydrator\Relationship\ToOneRelationship` /
  `ToManyRelationship`. These are construct-only value objects carrying the
  parsed linkage from a request body; an empty linkage (`null`/`[]`) means "clear
  the relationship".

When you use a [Resource class](resources.md#relationships) you declare relationships as
fields (`BelongsTo`, `HasMany`, …) and never touch either family directly — the
field bridges to both.

## Links

A **link** is a URL, optionally enriched with `meta` or the JSON:API 1.1 link
object members (`rel`, `describedby`, `title`, `type`, `hreflang`). The base type
is `Schema\Link\Link` (a bare `href` + optional `meta`, serializing to a string or
to a `{href, meta}` object); `LinkObject` and `ProfileLinkObject` extend it for the
richer forms.

Links are grouped into keyed containers — `Schema\Link\AbstractLinks` and its
subclasses `DocumentLinks`, `ResourceLinks`, `RelationshipLinks`, `ErrorLinks`.
A container holds a `baseUri` that is prepended to each member's `href` at render
time, so links you build with relative paths come out absolute. The reserved
relations (`self`, `related`, `first`/`prev`/`next`/`last`) have named accessors,
and arbitrary custom relations are permitted alongside them. `DocumentLinks` is
the one you attach to a response via `withLinks()`:

```php
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;

$links = DocumentLinks::withBaseUri('https://example.test', self: new Link('/articles/1'));
```

Pagination links (`first`/`prev`/`next`/`last`) are emitted automatically when you
return a [paginated response](pagination.md) via `DataResponse::fromPage()`; you
rarely set them by hand.

## The `jsonapi` object

The top-level `jsonapi` member advertises the spec version the server implements
and may carry its own `meta`. It is `Schema\JsonApiObject` — a `final readonly`
value object whose `version` defaults to the constant `JsonApiObject::VERSION`
(`'1.1'`):

```json
{ "jsonapi": { "version": "1.1" } }
```

You do not normally construct one: every response resolves a default `jsonapi`
object from the [server](server.md) (`jsonApiVersion()` + `defaultMeta()`). Supply
a custom one with `withJsonApi()` when you need extra `jsonapi.meta`.

## Meta

`meta` is a free-form `array<string, mixed>` of non-standard information, permitted
at the document level, inside resource objects, relationships, links, errors, and
the `jsonapi` object. Throughout the library `meta` is a plain associative array,
and an empty array means "omit the member". At the document level you set it with a
response's `withMeta()`.

## Errors

When something goes wrong the document carries `errors` instead of `data`: a list
of **error objects**, each describing one problem. The library models a single
error as `Schema\Error\Error` — a `final readonly` value object with optional
`id`, `status`, `code`, `title`, `detail`, `source`, `links`, and `meta` members
(every member is omitted when empty):

```php
use haddowg\JsonApi\Schema\Error\Error;

new Error(status: '404', code: 'RESOURCE_NOT_FOUND', title: 'Resource not found');
```

The `source` member (`Schema\Error\ErrorSource`) locates the cause of the error —
a JSON Pointer into the request body (`fromPointer()`), a query parameter
(`fromParameter()`), or a header (`fromHeader()`). Errors usually reach you as
[typed exceptions](exceptions.md) thrown deep in the request lifecycle and rendered
by the [error handler](errors.md); you build `Error` objects directly only when
returning an [`ErrorResponse::fromErrors()`](responses.md).

## Related pages

- [Responses](responses.md) — the response value objects that produce documents.
- [Resources](resources.md) — declaring a resource type's fields.
- [Errors](errors.md) — how errors propagate and render.
- [Exceptions](exceptions.md) — the typed exception hierarchy.
- [Architecture](architecture.md) — how a request flows through the library.
- [Documentation index](README.md) — the full page list.
