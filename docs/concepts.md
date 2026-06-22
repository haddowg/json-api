# Concepts

This page gives you the shared mental model the rest of the documentation relies
on: what a JSON:API *document* is, the three things the word "resource" can mean,
and the small vocabulary of identifiers, relationships, links, and errors that
every message is built from. It is conceptual — it describes the structures the
spec defines and notes where each one lives in the code — not a how-to. For
building responses see [Responses](responses.md); for declaring a resource type
see [Resources](resources.md). New here? Start with the
[documentation index](index.md) and the [getting-started](getting-started.md)
walkthrough.

The [JSON:API 1.1 specification](https://jsonapi.org/format/1.1/) defines the
shape of every message exchanged with the API. The library models those shapes as
a layered set of value objects. Most are `@internal` — the serialization engine
builds them for you and you never construct one — but understanding the model
makes the consumer-facing surface (Resource classes, response value objects) much
easier to reason about.

## The three meanings of "resource"

The word "resource" is overloaded across the spec and this library. Three
distinct things wear the name, and the documentation keeps them apart:

- **Resource object** — the spec structure `{type, id, attributes, relationships}`
  that appears inside a document's `data` (and inside `included`). This is "a
  resource" in the spec sense. The engine emits it as a **plain array**; there is
  **no `ResourceObject` class** to instantiate.
- **Resource class** — a [`Resource\AbstractResource`](resources.md) subclass such
  as [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php).
  This is a *per-type serializer + hydrator*: one `fields()` declaration that tells
  the engine how to turn a domain object into a resource object and how to fill a
  domain object from a request. It is the recommended surface you write to describe
  a JSON:API resource type.
- **Serializer / Hydrator** — the lower-level `Serializer\SerializerInterface` /
  `Hydrator\HydratorInterface` contracts a Resource class satisfies. Either is
  usable directly for full control when the field walk isn't enough — for example
  the example app's
  [`TrackSerializer`](../examples/music-catalog/src/Serializer/TrackSerializer.php)
  takes over reads for the `tracks` type. See [Custom serializers](serializers.md).

So: a *Resource class* and a *serializer* both produce *resource objects*. The 95%
path is to write a Resource class and never touch the other two terms.

## Documents

A JSON:API **document** is the top-level JSON object of every request and response
body. Per the spec it carries at most one of `data` or `errors`, plus optional
`meta`, `links`, `jsonapi`, and `included` members. The library models documents
under `Schema\Document\*` — `SingleResourceDocument`, `CollectionDocument`,
`MetaDocument`, and `ErrorDocument` — behind the `@internal`
`Schema\Document\DocumentInterface`.

> **You never write a document subclass.** Documents are internal, per-render
> machinery. Consumers return one of the six [response value
> objects](responses.md); each builds the appropriate internal document during
> rendering. The document layer is documented here only so the output structure is
> legible.

Every document shares three optional top-level members, captured by
`DocumentInterface`: the [`jsonapi` object](#the-jsonapi-object), `meta`, and
`links`. The response value objects expose these through their `withJsonApi()`,
`withMeta()`, and `withLinks()` withers.

### The six response value objects

You produce a document *indirectly* by returning a response VO from an operation
handler. There are six:

| Response VO | Document it builds | Typical use |
|---|---|---|
| `DataResponse` | single resource or collection | `GET /albums/1`, `GET /albums`, `POST` echo |
| `RelatedResponse` | a related resource / collection | `GET /albums/1/artist` |
| `IdentifierResponse` | resource-identifier linkage | `GET /albums/1/relationships/artist` |
| `MetaResponse` | a meta-only document | a document with no `data`, just `meta` |
| `ErrorResponse` | an error document | any failure path |
| `NoContentResponse` | empty `204` | `DELETE /albums/1` |

See [Responses](responses.md) for their constructors and withers.

## Resource objects

A resource object is the `{type, id, attributes, relationships}` structure inside
`data`. The library builds it field-by-field from your
[Resource class](resources.md): the `Id` field becomes the top-level `id`,
attribute fields become `attributes`, relationship fields become `relationships`,
and the `type` member comes from the Resource class's static `$type` (e.g.
`AlbumResource::$type = 'albums'`).

```json
{
    "type": "albums",
    "id": "1",
    "attributes": { "title": "OK Computer" },
    "relationships": {
        "artist": { "data": { "type": "artists", "id": "9" } }
    }
}
```

Sparse fieldsets (`?fields[albums]=title`) and inclusion (`?include=artist`) are
applied by the engine as it walks the Resource class — the Resource class emits
every eligible field and the engine narrows the output to match the request. See
[fields](fields.md) and [sparse fieldsets and includes](sparse-fieldsets-and-includes.md).

## Resource identifiers

A **resource identifier** references a resource without carrying its full
representation: just `{type, id}` (plus optional `meta`). It is the `data` of a
relationship and the contents of a `relationships/…` linkage endpoint. In code it
is `Schema\ResourceIdentifier` — a `final readonly` value object with public
`type`, `id`, `lid`, and `meta` properties:

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
nullable, and `fromArray()` requires `type` plus at least one of `id` / `lid` —
otherwise it throws the typed `ResourceIdentifierIdMissing`. A `lid` is a local
handle, never the resource's identity: a resource created with a `lid` still
receives a server-generated `id`, and the request exposes the supplied `lid`
separately.

> Resolving a `lid` to a freshly-created resource within one request is **not
> supported**. A `lid` parses, validates, and flows through to the hydrator, but
> the library does not wire it back to a created resource for you — that is the
> scope boundary.

## Relationships

A **relationship** connects one resource to others. The spec distinguishes
*to-one* and *to-many* relationships:

- **to-one** data is a single resource identifier, or `null`;
- **to-many** data is a list of resource identifiers.

On input, an **empty linkage** (`null` for to-one, `[]` for to-many) means "clear
the relationship".

The library has two distinct relationship type families because input and output
have different needs:

- **Output (serialization) side** — `Schema\Relationship\ToOneRelationship` /
  `ToManyRelationship` (over `AbstractRelationship`). These are *builders* a
  serializer constructs to describe a resource's relationships; they own the
  inclusion and deduplication logic that drives compound documents (`included`).
- **Input (hydration) side** — `Hydrator\Relationship\ToOneRelationship` /
  `ToManyRelationship`. These are construct-only value objects carrying the parsed
  linkage from a request body.

When you use a [Resource class](resources.md) you declare relationships as fields
(`BelongsTo`, `HasMany`, `HasOne`, `BelongsToMany`, …) and never touch either
family directly — the field bridges to both. For example
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php)
declares `BelongsTo::make('artist', 'artists')` (to-one) and
`HasMany::make('tracks', 'tracks')` (to-many). See [relationships](relations.md).

## Links

A **link** is a URL, optionally enriched with `meta` or the JSON:API 1.1 link
object members (`rel`, `describedby`, `title`, `type`, `hreflang`). A link
serializes in one of two forms: a **bare string** (`"href"`) when it is just a
URL, or a **link object** (`{href, meta, …}`) when it carries extra members. The
base type is `Schema\Link\Link`; `LinkObject` and `ProfileLinkObject` extend it for
the richer forms.

Links are grouped into keyed containers — `Schema\Link\AbstractLinks` and its
subclasses `DocumentLinks`, `ResourceLinks`, `RelationshipLinks`, `ErrorLinks`. A
container holds a `baseUri` that is prepended to each member's `href` at render
time, so links you build with relative paths come out absolute. For the
auto-emitted links, that base is the [configured base URI or, when none is set,
the request origin](server.md#base-uri-and-the-request-origin). The reserved
relations (`self`, `related`, `first` / `prev` / `next` / `last`) have named
accessors, and arbitrary custom relations are permitted alongside them.
`DocumentLinks` is the one you attach to a response via `withLinks()`:

```php
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;

$links = DocumentLinks::withBaseUri('https://music.example', self: new Link('/albums/1'));
```

Pagination links (`first` / `prev` / `next` / `last`) are emitted automatically
when you return a [paginated response](pagination.md) via
`DataResponse::fromPage()`; you rarely set them by hand.

## The `jsonapi` object

The top-level `jsonapi` member advertises the spec version the server implements
and may carry its own `meta`. It is `Schema\JsonApiObject` — a `final readonly`
value object whose `version` defaults to the constant `JsonApiObject::VERSION`
(`'1.1'`):

```json
{ "jsonapi": { "version": "1.1" } }
```

You do not normally construct one: every response resolves a default `jsonapi`
object from the [server](server.md). Supply a custom one with `withJsonApi()` when
you need extra `jsonapi.meta`.

## Meta

`meta` is a free-form `array<string, mixed>` of non-standard information, permitted
at the document level and inside resource objects, relationships, links, errors,
and the `jsonapi` object. Throughout the library `meta` is a plain associative
array, and an **empty array means "omit the member"**. At the document level you
set it with a response's `withMeta()`.

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

The `source` member (`Schema\Error\ErrorSource`) locates the cause of the error.
It is one of a triad — a JSON Pointer into the request body, a query parameter, or
a request header — each with a named constructor:

| Constructor | Locates | Example value |
|---|---|---|
| `ErrorSource::fromPointer()` | a JSON Pointer into the body | `/data/attributes/title` |
| `ErrorSource::fromParameter()` | a query parameter | `filter[slug]` |
| `ErrorSource::fromHeader()` | a request header | `Accept` |

Errors usually reach you as [typed exceptions](errors-and-exceptions.md) thrown deep in the
request lifecycle and rendered by the [error handler](errors-and-exceptions.md) — for example a
missing album surfaces as
[`ResourceNotFound`](../examples/music-catalog/tests/GettingStartedTest.php) and a
`404`. You build `Error` objects directly only when returning an
[`ErrorResponse::fromErrors()`](responses.md).

## Next

- [Architecture](architecture.md) — how a request flows through these parts.
- [Responses](responses.md) — the six response value objects in depth.
- [Resources](resources.md) and [fields](fields.md) — declaring a resource type.
- [Errors](errors-and-exceptions.md) and [exceptions](errors-and-exceptions.md) — how problems propagate.
- [Documentation index](index.md) — the full page list.
