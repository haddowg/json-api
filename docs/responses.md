# Responses

A **response value object** is how you describe a JSON:API response without ever
building a document by hand. You construct one with a named constructor, optionally
chain `with…()` methods to set document-level members, and return it from an
[operation handler](server.md#operations); the library renders it to a spec-compliant
PSR-7 response for you. There are five, one per kind of top-level document:
`DataResponse`, `MetaResponse`, `ErrorResponse`, `RelatedResponse`, and
`IdentifierResponse`.

> **You never write a `Schema\Document\*` subclass.** Documents are `@internal`
> machinery. Each response value object builds the right internal document when it
> renders. The response value objects are the entire public "return a response"
> surface. See [Concepts](concepts.md#documents).

All five extend `Response\AbstractResponse` and share the same immutability
contract: like `JsonApiRequest`, they are **not** `readonly`; each `with…()` does
clone-then-assign and returns a new `static`, so a response value is safe to pass
around and reuse.

## `DataResponse`

The common case: a document whose primary `data` is a resource or a collection of
resources. Whether the response is a single resource or a collection is fixed by
the named constructor you choose — it is never inferred from the runtime shape of
the data, so an iterable single resource is never mistaken for a collection.

```php
use haddowg\JsonApi\Response\DataResponse;

// Single resource:
return DataResponse::fromResource($article, $server->serializerFor('articles'));

// Collection:
return DataResponse::fromCollection($articles, $server->serializerFor('articles'));
```

Both take the domain value(s) plus the [serializer](serializers.md) that renders
them (resolve it from the [server](server.md) with `serializerFor($type)`). The
third constructor, `fromPage()`, renders a [paginated](pagination.md) collection —
the page supplies the `data`, and the document gains the pagination
`links.{first,prev,next,last}` and `meta.page` automatically:

```php
$page = PagePaginator::make()->paginate($request, $items, $total);
return DataResponse::fromPage($page, $server->serializerFor('articles'));
```

> `DataResponse::render()` always produces **HTTP 200**. The library does not infer
> `201 Created` for a create — if your endpoint must return a different status,
> set it on the PSR-7 response after rendering, or adjust it in your framework's
> response pipeline.

## `MetaResponse`

A meta-only document: top-level `meta` (and optionally `jsonapi`/`links`) with no
primary `data`. Useful for endpoints that report state without returning a
resource.

```php
use haddowg\JsonApi\Response\MetaResponse;

return MetaResponse::fromMeta(['jobId' => 'abc123', 'status' => 'queued']);
```

## `ErrorResponse`

A document carrying one or more [error objects](errors.md). Build it from existing
`Schema\Error\Error` value objects with `fromErrors()`, or from any thrown
[typed exception](exceptions.md) with `fromException()`:

```php
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;

return ErrorResponse::fromException(new ResourceNotFound());

return ErrorResponse::fromErrors(
    new Error(status: '422', code: 'INVALID', title: 'Validation failed'),
);
```

The HTTP status is derived from the errors themselves (a single error uses its own
`status`; a mix is rounded to the nearest applicable class). You rarely construct
an `ErrorResponse` in normal flow — throwing a typed exception and letting the
[error handler](errors.md) catch it is the idiomatic path. See [Errors](errors.md).

## `RelatedResponse`

The response for a *related-resources* endpoint (`GET /articles/1/author`,
`GET /articles/1/comments`): the primary `data` is the **related** resource or
collection, serialized through the related type's serializer. The parent object
and relationship name are carried for context.

```php
use haddowg\JsonApi\Response\RelatedResponse;

return RelatedResponse::fromResource(
    $article, 'author', $article->author, $server->serializerFor('people'),
);

return RelatedResponse::fromCollection(
    $article, 'comments', $article->comments, $server->serializerFor('comments'),
);
```

## `IdentifierResponse`

The response for a *relationship-linkage* endpoint
(`GET /articles/1/relationships/comments`): it emits resource-identifier linkage
only — `{type, id}` objects with no attributes or relationships — for the named
relationship on the parent resource.

```php
use haddowg\JsonApi\Response\IdentifierResponse;

return IdentifierResponse::forRelationship(
    $article, $server->serializerFor('articles'), 'comments',
);
```

## Document-level members: the `with…()` withers

`AbstractResponse` gives every response the same fluent surface for the
document-level members. Each returns a new instance:

| Method | Sets |
|---|---|
| `withMeta(array $meta)` | the document `meta` member |
| `withLinks(?DocumentLinks $links)` | the document `links` member |
| `withJsonApi(?JsonApiObject $jsonApi)` | the top-level `jsonapi` object |
| `withHeader(string $name, string $value)` | one extra HTTP response header |
| `withHeaders(array $headers)` | replaces all extra HTTP headers |
| `withEncodeOptions(int $encodeOptions)` | per-response `json_encode` flags (overrides the server default) |

```php
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;

return DataResponse::fromResource($article, $articles)
    ->withMeta(['generatedAt' => $now])
    ->withLinks(DocumentLinks::withBaseUri('https://example.test', self: new Link('/articles/1')));
```

The response-specific payload (the data + its serializer, or the error list) is
fixed at construction and is **not** withable — choose it with the named
constructor. Only the document-level members above are mutable.

## Returning a response from a handler

Inside an [`OperationHandler`](server.md#operations) you simply return the value
object — the adapter renders it. The handler never touches PSR-7:

```php
public function handle(JsonApiOperation $operation): DataResponse|ErrorResponse
{
    $server = $operation->context()->server;
    \assert($server instanceof Server);

    $article = $this->repository->find((string) $operation->target()->id);

    return $article === null
        ? ErrorResponse::fromException(new ResourceNotFound())
        : DataResponse::fromResource($article, $server->serializerFor('articles'));
}
```

`OperationHandler::handle()` returns the union
`DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse`, so
narrow your declared return type to the responses your handler actually produces.

## Rendering outside the operations flow

When you are not going through the operations adapter (a custom PSR-15 handler, a
test, a one-off script) you render a response value object yourself with
`toPsrResponse()`, passing the [server](server.md) and the originating request:

```php
$psrResponse = DataResponse::fromResource($article, $articles)
    ->toPsrResponse($server, $request);
```

`toPsrResponse()` runs the transformer to build the body array, applies any in-scope
[profiles](profiles.md#how-applied-profiles-are-surfaced), `json_encode`s the body (with
`JSON_THROW_ON_ERROR` and the resolved encode options) using the server's PSR-17
factories, and returns a PSR-7 response with `Content-Type:
application/vnd.api+json`. If the originating request is a plain
`ServerRequestInterface` it is wrapped in a `JsonApiRequest` automatically.

## Related pages

- [Server](server.md) — `serializerFor()`, operations, and dispatch.
- [Pagination](pagination.md) — `DataResponse::fromPage()` and the `Page` objects.
- [Errors](errors.md) — how `ErrorResponse` fits the error-handling flow.
- [Concepts](concepts.md) — the documents these responses produce.
- [Documentation index](README.md) — the full page list.
