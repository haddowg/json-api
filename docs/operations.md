# Operations and dispatch

A JSON:API request — once negotiated, parsed and routed — becomes a single
*operation* value object that you hand to *one* method you implement. This page is
the model behind that: the nine operation VOs, the [`Target`](#target) that
identifies the endpoint, the [`OperationFactory`](#operationfactory-from-request-to-operation)
that picks the right operation, the [`OperationContext`](#operationcontext) and
[`QueryParameters`](#queryparameters) carried alongside, and the
[`OperationHandlerInterface`](#operationhandlerinterface-the-one-seam) you write —
plus the [PSR-7 adapter](#psr7tooperationhandleradapter-the-psr-15-join) that joins
it all to a PSR-15 stack. Read this if you are writing a handler or a custom
integration.

For how the surrounding lifecycle is assembled (the middleware that runs before
your handler), see [architecture](architecture.md) and [middleware](middleware.md).
In order, a request flows through: negotiate content type, parse the body, route
and attach the [`Target`](#target), build the operation with the
[`OperationFactory`](#operationfactory-from-request-to-operation), call your
[handler](#operationhandlerinterface-the-one-seam), then render its response VO. The
fuller stage-by-stage account is in [architecture](architecture.md#request-flow).

## The common contract

Every operation implements [`JsonApiOperationInterface`](../src/Operation/JsonApiOperationInterface.php):
three accessors that tell you what endpoint you are on, the query parameters in
effect, and the ambient context.

```php
interface JsonApiOperationInterface
{
    public function target(): Target;

    public function queryParameters(): QueryParameters;

    public function context(): OperationContext;
}
```

The five *mutating* operations — create, update, delete-relationship-member, and
the two relationship-mutation verbs — add one more method, `body(): JsonApiRequestInterface`,
giving you the parsed write document. The other four operations have no body: the
three reads (`FetchResourceOperation`, `FetchRelatedOperation`,
`FetchRelationshipOperation`) plus `DeleteResourceOperation`.

Dispatching on the concrete operation *type* (rather than inspecting an HTTP verb)
is the whole point: each verb-crossed-with-shape combination is its own class, so a
`match (true)` over types is exhaustive and type-safe — the body is present exactly
where the type guarantees it.

## The nine operations

There are nine concrete operation VOs, one per JSON:API endpoint. The HTTP method
crossed with the [`Target`](#target) shape selects which one you receive.

| Operation | Endpoint | Body? |
|---|---|---|
| [`FetchResourceOperation`](../src/Operation/FetchResourceOperation.php) | `GET /albums` (collection) or `GET /albums/1` (single — `target()->hasId()`) | — |
| [`FetchRelatedOperation`](../src/Operation/FetchRelatedOperation.php) | `GET /albums/1/artist` (the related resource(s)) | — |
| [`FetchRelationshipOperation`](../src/Operation/FetchRelationshipOperation.php) | `GET /albums/1/relationships/tracks` (linkage) | — |
| [`CreateResourceOperation`](../src/Operation/CreateResourceOperation.php) | `POST /albums` | yes |
| [`UpdateResourceOperation`](../src/Operation/UpdateResourceOperation.php) | `PATCH /albums/1` | yes |
| [`DeleteResourceOperation`](../src/Operation/DeleteResourceOperation.php) | `DELETE /albums/1` | — |
| [`UpdateRelationshipOperation`](../src/Operation/UpdateRelationshipOperation.php) | `PATCH /tracks/1/relationships/playlists` (replace) | yes |
| [`AddToRelationshipOperation`](../src/Operation/AddToRelationshipOperation.php) | `POST /tracks/1/relationships/playlists` (add) | yes |
| [`RemoveFromRelationshipOperation`](../src/Operation/RemoveFromRelationshipOperation.php) | `DELETE /tracks/1/relationships/playlists` (remove) | yes |

A `FetchResourceOperation` covers both the collection and the single-resource read:
the same operation serves `GET /albums` and `GET /albums/1`, and you branch on
`target()->id` (or the convenience `target()->hasId()`) inside your handler.

## A handler as a match over operation types

The music-catalog example app implements exactly one handler. Its `handle()` is a
`match (true)` over the nine concrete types — the canonical shape for a handler.
Each arm calls a private method that knows how to service that one operation; the
`default` arm guards against an unknown operation type with a `404`.

From [`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php):

```php
public function handle(
    JsonApiOperationInterface $operation,
): DataResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse {
    return match (true) {
        $operation instanceof FetchResourceOperation => $this->fetch($operation),
        $operation instanceof FetchRelatedOperation => $this->fetchRelated($operation),
        $operation instanceof FetchRelationshipOperation => $this->fetchRelationship($operation),
        $operation instanceof CreateResourceOperation => $this->create($operation),
        $operation instanceof UpdateResourceOperation => $this->update($operation),
        $operation instanceof DeleteResourceOperation => $this->delete($operation),
        $operation instanceof UpdateRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Replace),
        $operation instanceof AddToRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Add),
        $operation instanceof RemoveFromRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Remove),
        default => ErrorResponse::fromException(new ResourceNotFound()),
    };
}
```

The handler's return union here lists five response VOs; the sixth allowed return,
the meta-only [`MetaResponse`](responses.md#metaresponse), is simply one the
music-catalog handler never produces (the full closed union is shown under
[`OperationHandlerInterface`](#operationhandlerinterface-the-one-seam)).

Notice the single read arm for collection-or-single, and that the three
relationship-mutation arms read `$operation->body()` — only available because those
concrete types declare it. Each arm reaches the serializer/hydrator registries
through the operation's `context()` (see [below](#operationcontext)). What each arm
*returns* is covered in [responses](responses.md); the read arms are detailed in
[related endpoints](related-endpoints.md) and the mutation arms in
[relationship mutation](relationship-mutation.md).

## Target

[`Target`](../src/Operation/Target.php) is the router-agnostic identifier for the
endpoint an operation acts on, independent of PSR-7. It names the primary resource
`type`, optionally a specific `id`, and optionally a `relationship`; a flag
distinguishes the two relationship shapes.

```php
final readonly class Target
{
    public function __construct(
        public string $type,
        public ?string $id = null,
        public ?string $relationship = null,
        public bool $isRelationshipEndpoint = false,
    ) {}

    public function hasId(): bool { /* … */ }
    public function hasRelationship(): bool { /* … */ }
}
```

It is a leaf value object: the readonly properties *are* the accessors, with
`hasId()` / `hasRelationship()` as convenience predicates. The four endpoint shapes
map onto it directly:

| Path | `id` | `relationship` | `isRelationshipEndpoint` |
|---|---|---|---|
| `/albums` | `null` | `null` | `false` |
| `/albums/1` | `"1"` | `null` | `false` |
| `/albums/1/artist` (related) | `"1"` | `"artist"` | `false` |
| `/albums/1/relationships/tracks` (linkage) | `"1"` | `"tracks"` | `true` |

Routing is *your* job — the library never parses a path. Your framework's router
(or, in the example, the toy [`PathPrefixRouter`](../examples/music-catalog/src/Http/PathPrefixRouter.php))
builds a `Target` and attaches it as a PSR-7 request attribute **keyed by
`Target::class`** — the single attribute the [adapter](#psr7tooperationhandleradapter-the-psr-15-join)
reads:

```php
return $handler->handle($request->withAttribute(Target::class, $target));
```

## OperationFactory: from request to operation

[`OperationFactory`](../src/Operation/OperationFactory.php) is the single source of
truth for the dispatch decision: given a parsed request, a `Target` and a context,
it constructs the one concrete operation matching the HTTP method crossed with the
target shape.

```php
public function fromRequest(
    JsonApiRequestInterface $request,
    Target $target,
    OperationContext $context,
): JsonApiOperationInterface {
    $query = QueryParameters::fromRequest($request);
    $hasRelationship = $target->hasRelationship();

    return match (\strtoupper($request->getMethod())) {
        'GET' => match (true) {
            $hasRelationship === false => new FetchResourceOperation($target, $query, $context),
            $target->isRelationshipEndpoint => new FetchRelationshipOperation($target, $query, $context),
            default => new FetchRelatedOperation($target, $query, $context),
        },
        'POST' => $hasRelationship
            ? new AddToRelationshipOperation($target, $query, $context, $request)
            : new CreateResourceOperation($target, $query, $context, $request),
        'PATCH' => $hasRelationship
            ? new UpdateRelationshipOperation($target, $query, $context, $request)
            : new UpdateResourceOperation($target, $query, $context, $request),
        'DELETE' => $hasRelationship
            ? new RemoveFromRelationshipOperation($target, $query, $context, $request)
            : new DeleteResourceOperation($target, $query, $context),
        default => throw new \haddowg\JsonApi\Exception\ApplicationError(),
    };
}
```

It is a public, stateless seam — construct it, then call `fromRequest()`. A few
deliberate boundaries:

- It takes the **already-parsed** `JsonApiRequestInterface`, so the body source and
  `QueryParameters::fromRequest()` read from the same memoized wrapper; wrapping and
  idempotency stay the caller's responsibility.
- It takes the `OperationContext` **explicitly** — each caller decides which HTTP
  request (if any) backs the context.
- It does **not** handle a missing target: the signature requires a non-null
  `Target`, keeping the no-route concern at the adapter edge.
- An unhandled HTTP method throws [`ApplicationError`](../src/Exception/ApplicationError.php)
  (a `500`).

Override or wrap `OperationFactory` if your custom integration needs a different
method-to-operation mapping; the [adapter](#psr7tooperationhandleradapter-the-psr-15-join)
accepts a factory as its third constructor argument.

## OperationContext

[`OperationContext`](../src/Operation/OperationContext.php) is the ambient context
every operation carries: the server (so a handler can resolve serializers and
hydrators) and, *when the operation came from HTTP*, the originating PSR-7 request.

```php
final readonly class OperationContext
{
    public function __construct(
        public ResolvingServerInterface $server,
        private ?ServerRequestInterface $httpRequest = null,
    ) {}

    public function httpRequest(): ?ServerRequestInterface { /* … */ }
}
```

`$server` is a [`ResolvingServerInterface`](../src/Server/ResolvingServerInterface.php)
— the render contract plus type-keyed serializer/hydrator resolution
(`serializerFor()` / `hydratorFor()`), which is all most handlers need from the
server (see [server](server.md)). The example narrows it to the concrete `Server`
to reach `resourceFor()` / `defaultPaginator()` as well.

The HTTP request is **optional and private** behind `httpRequest()`. An operation
*dispatched programmatically* — constructed directly rather than adapted from a
PSR-7 message — has no HTTP message, so `httpRequest()` returns `null`. A handler
that needs the raw request must null-check. The example's helper does exactly this,
so the same handler serves an HTTP request and a programmatic dispatch:

```php
private function request(OperationContext $context): JsonApiRequestInterface
{
    $request = $context->httpRequest();
    if ($request instanceof JsonApiRequestInterface) {
        return $request;
    }

    // No HTTP message (a programmatic dispatch) — fall back to a bare GET so the
    // repository's criteria + window still have a request to read: an unfiltered,
    // unsorted, default-windowed read.
    return new JsonApiRequest(new \Nyholm\Psr7\ServerRequest('GET', '/'));
}
```

## QueryParameters

[`QueryParameters`](../src/Operation/QueryParameters.php) is the parsed projection of
the JSON:API query-parameter families, decoupled from the request so a handler can
be driven without a PSR-7 message:

```php
final readonly class QueryParameters
{
    public function __construct(
        public array $fields,       // sparse fieldsets, keyed type => field names
        public array $includes,     // include paths (a flat list)
        public array $sort,         // sort fields (leading "-" preserved)
        public array $filter,       // the filter map verbatim
        public array $pagination,   // the page map verbatim
    ) {}
}
```

Each member is the spec-shaped projection of one family; the readonly properties
*are* the accessors. `fromRequest()` is just the HTTP-side constructor — it reads
`sort` / `filter` / `page` from the request's own parsers and parses the raw
comma-separated `include` and `fields[type]` strings into lists. Malformed values
are tolerated (skipped) rather than thrown; well-formedness is the negotiation
layer's job. A programmatic caller can construct `QueryParameters` directly instead.
The individual families are documented in [sparse fieldsets and includes](sparse-fieldsets-and-includes.md),
[sorts](sorts.md), [filters](filters.md) and [pagination](pagination.md).

## OperationHandlerInterface: the one seam

[`OperationHandlerInterface`](../src/Operation/OperationHandlerInterface.php) is the
single consumer extension point of the operations layer. You implement *one* method;
given any operation, you return one of the public response value objects.

```php
interface OperationHandlerInterface
{
    public function handle(
        JsonApiOperationInterface $operation,
    ): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse;
}
```

The return type is a **closed union** of six response VOs — the only things a handler
may produce. The handler stays PSR-7-decoupled (the optional originating request is
reachable through `context()->httpRequest()`), and the [adapter](#psr7tooperationhandleradapter-the-psr-15-join)
encodes whatever you return back to PSR-7. The response VOs themselves are covered
in [responses](responses.md). You can also *decorate* a handler — wrap another
`OperationHandlerInterface` to add cross-cutting behaviour around the dispatch.

## Psr7ToOperationHandlerAdapter: the PSR-15 join

[`Psr7ToOperationHandlerAdapter`](../src/Operation/Psr7ToOperationHandlerAdapter.php)
is the join between the PSR-15 world and the operations layer. It is a
`RequestHandlerInterface` that, for each incoming PSR-7 request:

1. reads the `Target` from the request attribute keyed by `Target::class`;
2. wraps the request as a `JsonApiRequestInterface` (if it is not already one);
3. builds an `OperationContext` carrying the server and the originating request;
4. asks the `OperationFactory` for the matching operation;
5. calls your handler and **encodes** the returned response VO to PSR-7.

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $target = $request->getAttribute(Target::class);
    if ($target instanceof Target === false) {
        // Routing failed to attach a Target — a server-side wiring fault, not a
        // client error. Render a 500 ErrorResponse rather than throwing.
        return ErrorResponse::fromException(new \haddowg\JsonApi\Exception\ApplicationError())
            ->toPsrResponse($this->server, $request);
    }

    $jsonApiRequest = $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request);
    $context = new OperationContext($this->server, $request);
    $operation = $this->factory->fromRequest($jsonApiRequest, $target, $context);
    $response = $this->handler->handle($operation);

    return $response->toPsrResponse($this->server, $request);
}
```

A **missing `Target`** is treated as a wiring fault: the adapter renders a `500`
`ErrorResponse` rather than throwing, so the PSR-15 contract still yields a valid
JSON:API response. This is why the example router answers a genuine no-match with
its own `404` (it simply never attaches a `Target`), reserving the adapter's `500`
for the truly unexpected case of a matched route that failed to attach one.

The adapter is the standard way to put a handler behind a PSR-15 stack. The Symfony
bundle **bypasses it**: it builds the operation from its own kernel listeners and
calls `Server::dispatch()` directly.

## Two ways to drive a handler

The same handler serves two dispatch paths from the [`Server`](server.md):

- **`Server::handle($request)`** runs the full PSR-15 middleware chain — negotiation,
  body parsing, routing — wrapped around the adapter, and returns an encoded PSR-7
  `ResponseInterface`. This is the HTTP entry point.
- **`Server::dispatch($operation)`** invokes the configured handler **directly**,
  bypassing the PSR-15 chain, and returns the **unrendered** response VO. The
  operation must be pre-constructed and complete, and a handler must be configured
  (`withHandler()`), or it throws a `LogicException`.

`dispatch()` is the path for programmatic use — a queue worker, a test, an internal
call — where you have no HTTP request. You build the operation yourself:

```php
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;

$operation = new FetchResourceOperation(
    new Target('albums', '1'),
    new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: []),
    new OperationContext($server), // no PSR-7 request — httpRequest() returns null
);

$response = $server->dispatch($operation); // a DataResponse VO, not yet rendered
```

Because the context carries no HTTP request, `context()->httpRequest()` returns
`null` inside the handler — which is exactly why the example's
[`request()` helper](#operationcontext) falls back to a bare `GET`. The same handler
code runs unchanged on both paths.

## Next

- [Responses](responses.md) — the six response VOs a handler returns, and how they
  render.
- [Related endpoints](related-endpoints.md) and
  [relationship mutation](relationship-mutation.md) — the read and mutation arms in
  depth.
- [Server](server.md) — configuring the server, `handle()` vs `dispatch()`, and
  serializer/hydrator resolution.
