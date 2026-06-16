# Response value objects

A **response value object** is how you describe a JSON:API response without ever
building a document by hand. You pick a named constructor for the shape of
document you want, optionally chain a few `with…()` methods to set document-level
members and the HTTP status, and return it from your
[operation handler](operations.md). The library renders it to a spec-compliant
PSR-7 response for you — runs the transformer, applies in-scope
[profiles](profiles.md), JSON-encodes the body, sets `Content-Type`. You never
touch PSR-7 inside a handler.

> **You never write a `Schema\Document\*` subclass.** Documents are `@internal`
> machinery; each response value object builds the right one when it renders. The
> response value objects are the entire public "return a response" surface. See
> [Concepts](concepts.md).

There are six, one per kind of outcome:

| Response | Document | Default status |
|---|---|---|
| [`DataResponse`](#dataresponse) | primary `data` (single, collection, or page) | `200` |
| [`MetaResponse`](#metaresponse) | meta-only (no `data`) | `200` |
| [`RelatedResponse`](#relatedresponse) | the related resource(s) at a related endpoint | `200` |
| [`IdentifierResponse`](#identifierresponse) | relationship linkage (identifiers only) | `200` |
| [`NoContentResponse`](#nocontentresponse) | empty body | `204` |
| [`ErrorResponse`](#errorresponse) | one or more [error objects](errors-and-exceptions.md) | derived from the errors |

All six extend `Response\AbstractResponse` and share one immutability contract:
like the wrap-once [`JsonApiRequest`](architecture.md), they are **not**
`readonly`; each `with…()` clones, assigns, and returns a new `static`, so a
response value is safe to pass around and reuse.

The worked referent for this whole page is the example app's single handler,
[`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php) —
one `match (true)` over the nine concrete operations, each arm returning one of
five of these value objects (`DataResponse`, `RelatedResponse`,
`IdentifierResponse`, `NoContentResponse`, `ErrorResponse`); `MetaResponse` is the
sixth in the [`OperationHandlerInterface`](operations.md) union but the example app
never returns it.

## `DataResponse`

The common case: a document whose primary `data` is a resource or a collection of
resources. Whether the response is single or a collection is fixed by the named
constructor you choose — it is never inferred from the runtime shape of the data,
so an iterable single resource is never mistaken for a collection.

```php
use haddowg\JsonApi\Response\DataResponse;

// Single resource — GET /albums/1:
return DataResponse::fromResource($model, $serializer);

// Collection — GET /albums:
return DataResponse::fromCollection($models, $serializer);
```

Both take the domain value(s) plus the [serializer](serializers.md) that renders
them — resolve it from the [server](server.md) with `$server->serializerFor($type)`.
The third constructor, `fromPage()`, renders a [paginated](pagination.md)
collection: the page supplies the `data`, and the document gains the pagination
`links.{first,prev,next,last}` and `meta.page` automatically. A page that
activates a profile (cursor pagination, say) makes the response advertise it.

The `fetch` arm of the handler shows all three in one place — a missing single
resource is a `404`, a collection is paginated when the repository returns a
`PageInterface` and a plain collection otherwise:

```php
// MusicCatalogHandler::fetch() — elided
if ($id !== null) {
    $model = $this->repository->fetchOne($type, $id);
    if ($model === null) {
        return ErrorResponse::fromException(new ResourceNotFound());
    }

    return DataResponse::fromResource($model, $serializer);
}
// …
$result = $this->repository->fetchCollection(/* … */);

if ($result instanceof PageInterface) {
    return DataResponse::fromPage($result, $serializer);
}

return DataResponse::fromCollection($result, $serializer);
```

### Overriding the status with `withStatus()`

`DataResponse` renders **`200`** by default. The library never infers `201 Created`
for you — when an endpoint must return a different status, set it explicitly with
`withStatus()`. The `create` arm returns the created resource as a `DataResponse`,
bumps the status to `201`, and adds a `Location` header:

```php
// MusicCatalogHandler::create() — elided
return DataResponse::fromResource($entity, $serializer)
    ->withStatus(201)
    ->withHeader('Location', $server->baseUri() . '/' . $uriType . '/' . $id);
```

`POST /albums` then returns `201` with `Location: https://music.example/albums/{id}`,
exercised end-to-end in
[`GettingStartedTest`](../examples/music-catalog/tests/GettingStartedTest.php).
`withStatus()` lives on `AbstractResponse`, so any response can override its
default — see the [withers table](#document-level-members-the-withers) below.

## `MetaResponse`

A meta-only document: top-level `meta` (and optionally `jsonapi`/`links`) with no
primary `data`. Useful for endpoints that report state without returning a
resource.

```php
use haddowg\JsonApi\Response\MetaResponse;

return MetaResponse::fromMeta(['jobId' => 'abc123', 'status' => 'queued']);
```

## `RelatedResponse`

The response for a *related* endpoint (`GET /albums/1/artist`,
`GET /albums/1/tracks`): the primary `data` is the **related** resource or
collection, serialized through the related type's serializer. It mirrors
`DataResponse` exactly — `fromResource`, `fromCollection`, `fromPage`:

```php
use haddowg\JsonApi\Response\RelatedResponse;

// A to-one — the related serializer is resolved from the actual related object,
// so a polymorphic relation renders the object's own type; an empty to-one
// renders `data: null`.
return RelatedResponse::fromResource($related, $serializer);

// A to-many, paginated — the pagination links are scoped to the related URL the
// client hit (e.g. /albums/1/tracks), not the primary collection.
return RelatedResponse::fromPage($result, $serializer);
```

`fromPage()` is the one to reach for on a queryable to-many: the per-relation
paginator resolves `relation → related resource → server default`, and the
`links` are scoped to the related-collection URL rather than the primary
collection. The full related-endpoint flow — including the polymorphic
serializer resolution and the empty-to-one `data: null` — lives in
[related endpoints](related-endpoints.md).

## `IdentifierResponse`

The response for a *relationship* endpoint
(`GET /albums/1/relationships/tracks`): it emits resource-identifier linkage only
— `{type, id}` objects with no attributes or relationships — for the named
relationship on the parent resource. You pass the **parent** object and the
**parent's** serializer; the relationship name routes the transformer through the
linkage path:

```php
use haddowg\JsonApi\Response\IdentifierResponse;

return IdentifierResponse::forRelationship(
    $parent, $server->serializerFor($type), $relationshipName,
);
```

The same value object is the success body for a relationship **mutation** —
`PATCH`/`POST`/`DELETE …/relationships/{rel}` render the mutated parent's linkage
back at `200`. See [relationship mutation](relationship-mutation.md).

## `NoContentResponse`

An empty `204 No Content`: the body **and** the `Content-Type` header are omitted
entirely (a `204` carries neither). The common case is a successful resource
deletion — the `delete` arm:

```php
use haddowg\JsonApi\Response\NoContentResponse;

// MusicCatalogHandler::delete() — elided
$this->repository->delete($type, (string) $id);

return NoContentResponse::create();
```

A `NoContentResponse` is **always `204` by construction** — you never call
`withStatus()` on it, and the document-level body withers (`withMeta`,
`withLinks`, `withJsonApi`) have nothing to attach to. `withHeader()` still
applies, so you can set response headers. `DELETE /albums/2 → 204` with an empty
body is witnessed in
[`WritesTest`](../examples/music-catalog/tests/WritesTest.php).

## `ErrorResponse`

A document carrying one or more [error objects](errors-and-exceptions.md). Build
it from existing `Schema\Error\Error` value objects with `fromErrors()`, or — far
more commonly — from any thrown [typed exception](errors-and-exceptions.md) with
`fromException()`:

```php
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;

return ErrorResponse::fromException(new ResourceNotFound());

return ErrorResponse::fromErrors(
    new Error(status: '422', code: 'INVALID', title: 'Validation failed'),
);
```

You rarely construct an `ErrorResponse` by hand in normal flow — **throw** a typed
exception anywhere downstream and the outermost
[error handler](errors-and-exceptions.md) catches it, calling `fromException()`
for you. Returning one from a handler is for the cases where you already hold the
errors (the handler's `404` arms above do exactly this).

### How the status is derived

The HTTP status is derived from the errors themselves:

- **`fromException()`** uses the status the exception declares — a typed `422`
  bag stays `422`, even with multiple violations, because the exception overrides
  the derivation.
- **`fromErrors()`** derives the status from the error objects: if they all carry
  the same `status`, that status is used verbatim — a bag of validation `422`s is
  a `422`, **not** a collapsed `400`. Only a genuinely mixed set falls back to its
  status class: each error maps to its class (`4xx` or `5xx`), and the rendered
  status is the highest class present, emitted as `400` or `500`.

So the worked contrast is:

| Errors | Rendered status | Why |
|---|---|---|
| a single `404` | `404` | trivially uniform |
| `422` + `422` | `422` | uniform — kept verbatim, never collapsed to `400` |
| `404` + `422` | `400` | mixed within `4xx` → the `4xx` class, emitted as `400` |
| `404` + `500` | `500` | spans `4xx`/`5xx` → highest class is `5xx`, emitted as `500` |

These cases are pinned in
[`ErrorResponseTest`](../tests/Response/ErrorResponseTest.php). The full
exception catalogue and the throw-vs-return guidance live in
[errors and exceptions](errors-and-exceptions.md).

## Document-level members: the withers

`AbstractResponse` gives every response the same fluent surface. Each returns a
new instance:

| Method | Sets |
|---|---|
| `withMeta(array $meta)` | the document `meta` member |
| `withLinks(?DocumentLinks $links)` | the document `links` member |
| `withJsonApi(?JsonApiObject $jsonApi)` | the top-level `jsonapi` object |
| `withHeader(string $name, string $value)` | one extra HTTP response header |
| `withHeaders(array $headers)` | replaces all extra HTTP response headers |
| `withEncodeOptions(int $encodeOptions)` | per-response `json_encode` flags (overrides the server default) |
| `withStatus(int $status)` | overrides the rendered default status (e.g. `201` on create) |

```php
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;

return DataResponse::fromResource($model, $serializer)
    ->withStatus(201)
    ->withMeta(['generatedAt' => $now])
    ->withHeader('Location', $server->baseUri() . '/albums/' . $id);
```

The response-specific payload — the data plus its serializer, or the error list —
is fixed at construction and is **not** withable: you choose it with the named
constructor. Only the document-level members above are mutable. On a
`NoContentResponse` the body withers have no body to attach to and the status
stays `204` by construction.

Every data/resource document (single, collection, related, relationship, meta —
not an error document) also carries a spec-recommended top-level `links.self`: the
URI that produced it (`{server.baseUri}{request.path}`, including the query string
on a filtered or sorted request), emitted by convention. A paginated collection's
per-page `self`, or a `self` you set with `withLinks()`, takes precedence. See
[links and meta](links-and-meta.md#auto-emitted-links-you-dont-set-by-hand).

## Returning a response from a handler

Inside an [`OperationHandlerInterface`](operations.md) you simply return the value
object — the operations adapter renders it. `handle()` declares the union of all
six:

```php
public function handle(
    JsonApiOperationInterface $operation,
): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse {
    // … match (true) over the operation types
}
```

Narrow your declared return type to the responses a given arm actually produces —
the example handler's `fetch()` returns `DataResponse|ErrorResponse`, `delete()`
returns `NoContentResponse|ErrorResponse`, and so on. The handler never touches
PSR-7.

## Rendering outside the operations flow

When you are not going through the operations adapter — a custom PSR-15 handler, a
test, a one-off script — you render a value object yourself with
`toPsrResponse()`, passing the [server](server.md) and the originating request:

```php
$psrResponse = DataResponse::fromResource($model, $serializer)
    ->toPsrResponse($server, $request);
```

`toPsrResponse()` runs the transformer to build the body array, applies any
in-scope [profiles](profiles.md) (recording them in `links.profile` and the
`Content-Type` `profile` parameter, and varying on `Accept`), `json_encode`s the
body with `JSON_THROW_ON_ERROR` and the resolved encode options, and returns a
PSR-7 response with `Content-Type: application/vnd.api+json`. The status is the
one `withStatus()` set, falling back to the rendered default; a bodiless render (a
`204`) omits the body and the `Content-Type` header. If the originating request is
a plain `ServerRequestInterface` it is wrapped in a `JsonApiRequest` automatically.

## Next / see also

- [Operations](operations.md) — the operation value objects each handler arm switches on.
- [Pagination](pagination.md) — `DataResponse::fromPage()` and the `Page` objects.
- [Related endpoints](related-endpoints.md) — `RelatedResponse`/`IdentifierResponse` in full.
- [Relationship mutation](relationship-mutation.md) — the `IdentifierResponse` write outcomes.
- [Errors and exceptions](errors-and-exceptions.md) — `ErrorResponse`, status derivation, and the exception catalogue.
- [Documentation index](index.md) — the full page list.
