# Getting started: your first music-catalog endpoint

By the end of this page you will have a running PSR-15 application that fetches and
creates `albums` and serves spec-compliant JSON:API responses — built from an empty
project, with every piece test-verified. You will also know exactly which pieces
the library provides and which you supply yourself.

This walkthrough is the front of the [music catalog](../examples/music-catalog/)
example app — the single source of truth for every snippet in these docs. Each
outcome below is asserted by a CI-run test
([`GettingStartedTest`](../examples/music-catalog/tests/GettingStartedTest.php)),
so the example cannot drift from the code. If you are still evaluating, start at the
[documentation index](index.md) — it covers install, requirements, and the pre-1.0
caveat.

## The pieces you provide

The library is framework- and storage-agnostic. To serve a resource type you supply
four things:

1. **A domain model** — any object or array. The library never dictates its shape:
   no base class, no ORM, no annotations.
2. **A Resource class** — an [`AbstractResource`](resources.md) subclass declaring
   the type's [fields](fields.md). This one declaration drives both serialization
   (model → JSON:API) and hydration (request → model).
3. **An operation handler** — your application logic, expressed as a function from a
   parsed [operation](operations.md) to a [response value object](responses.md). It
   never touches PSR-7.
4. **A router** — mapping a URL to a JSON:API [`Target`](operations.md). Core ships
   no router; this is your framework's job. The example uses a tiny path-prefix
   stand-in.

Everything between the HTTP message and your handler is the library's job: content
negotiation, body parsing, sparse fieldsets, includes, error rendering, and response
encoding.

## Step 1 — the domain model

A plain mutable object. No base class, no ORM, no annotations — the relationships
are simply held as the related objects, so a default reader returns them straight
off the model. From [`Album`](../examples/music-catalog/src/Domain/Album.php):

```php
final class Album
{
    /**
     * @param list<Track> $tracks
     */
    public function __construct(
        public string $id = '',
        public string $title = '',
        public bool $explicit = false,
        // … other columns elided
        public ?Artist $artist = null,
        public array $tracks = [],
    ) {}
}
```

A trivial in-memory store stands in for a database. The example app keeps reads and
writes against one shared
[`InMemoryStore`](../examples/music-catalog/src/Data/InMemoryStore.php) behind an
[`InMemoryRepository`](../examples/music-catalog/src/Data/InMemoryRepository.php), so
a created resource is immediately readable.

## Step 2 — the Resource class

A Resource class declares the type's fields. This one list is the single source of
truth for both directions: it tells the serializer how to render an `Album` and the
hydrator how to fill one from a request body. From
[`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) (its
richer fields elided to the two essentials):

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
            Boolean::make('explicit'),
            // … relations and richer fields — see resources.md / fields.md
        ];
    }
}
```

`$type` is the JSON:API type **and** the registry key. [`Id::make()`](ids.md) maps to
the top-level `id`; each [`Str`](field-types.md)/[`Boolean`](field-types.md) field
becomes an attribute. `required()`, `maxLength()` and `sortable()` are declarative
[metadata](constraints.md) — the field surface is documented in full in
[Fields](fields.md). See [Resources](resources.md) for the rest of the
`AbstractResource` contract.

## Step 3 — the operation handler

Your handler receives a parsed
[`JsonApiOperationInterface`](operations.md) and returns one of the
[response value objects](responses.md). It never touches PSR-7 — the framing is done
for you. Dispatch on the concrete operation type with `match (true)`; the type
system narrows each branch. This is the shape of
[`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php),
reduced to the two arms this page exercises:

```php
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;

final class MusicCatalogHandler implements OperationHandlerInterface
{
    public function handle(JsonApiOperationInterface $operation): DataResponse|ErrorResponse
    {
        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
            $operation instanceof CreateResourceOperation => $this->create($operation),
            // … the other seven operation VOs — see operations.md
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }
}
```

### Reaching the registry

A handler reaches the registered Resource through `$operation->context()->server`.
That property is typed as the minimal `ResolvingServerInterface`; narrow it to the
concrete [`Server`](server.md) to reach `serializerFor()` / `hydratorFor()`:

```php
$server = $operation->context()->server;
\assert($server instanceof Server);
```

### Fetching: collection vs single

A `FetchResourceOperation` covers both `GET /albums` and `GET /albums/1`. The two are
distinguished by `target()->hasId()`. A single fetch that finds nothing returns an
[`ErrorResponse`](responses.md) from [`ResourceNotFound`](errors-and-exceptions.md);
a found one renders through `DataResponse::fromResource()`. The handler reads the
type off the operation's [`Target`](operations.md) — `$type` below is
`$operation->target()->type`:

```php
$type = $operation->target()->type;
$serializer = $server->serializerFor($type);

$id = $operation->target()->id;
if ($id !== null) {
    $model = $this->repository->fetchOne($type, $id);
    if ($model === null) {
        return ErrorResponse::fromException(new ResourceNotFound());
    }

    return DataResponse::fromResource($model, $serializer);
}

// A collection (the example app paginates here; the simplest form is a plain list):
return DataResponse::fromCollection($this->repository->fetchAll($type), $serializer);
```

`DataResponse::fromResource(mixed $object, SerializerInterface $resource)` and
`fromCollection(iterable $objects, SerializerInterface $resource)` are the two
read builders (the second parameter is the serializer, named `$resource`). (The
full handler also paginates collections — see [Pagination](pagination.md).)

### Creating: hydrate, persist, 201

A `CreateResourceOperation` carries the parsed request `body()`. Drive the per-type
hydrator with `hydratorFor($type)->hydrate($body, $newInstance)` to fill a fresh
domain object, persist it, then render `201` with a `Location` header. The
[`Server`](server.md) never dictates instantiation — the handler owns a tiny
type → `new Album()` map (`$type` is again `$operation->target()->type`):

```php
$type = $operation->target()->type;
$serializer = $server->serializerFor($type);

$entity = $server->hydratorFor($type)->hydrate($operation->body(), new Album());
\assert(\is_object($entity));

$id = $serializer->getId($entity);
$this->repository->create($type, $entity, $id);

return DataResponse::fromResource($entity, $serializer)
    ->withStatus(201)
    ->withHeader('Location', $server->baseUri() . '/' . $type . '/' . $id);
```

`withStatus(int)` and `withHeader(string, string)` are immutable withers on every
response VO — each returns a new instance. With no client id supplied, the server
[generates one](ids.md) (an RFC-4122 v4 UUID by default); `Location` echoes it.

## Step 4 — the router

Core deliberately ships no router — mapping a URL to a resource is your framework's
concern. A router's only job here is to attach a [`Target`](operations.md) to the
request as an attribute keyed by `Target::class`; the operations adapter reads it to
pick the operation. From
[`PathPrefixRouter`](../examples/music-catalog/src/Http/PathPrefixRouter.php),
matching the four JSON:API endpoint shapes:

```php
use haddowg\JsonApi\Operation\Target;

return match ($count) {
    // /{type}
    1 => new Target($type),
    // /{type}/{id}
    2 => new Target($type, $segments[1]),
    // /{type}/{id}/{relationship}
    3 => new Target($type, $segments[1], $segments[2], isRelationshipEndpoint: false),
    // /{type}/{id}/relationships/{relationship}
    4 => $segments[2] === 'relationships'
        ? new Target($type, $segments[1], $segments[3], isRelationshipEndpoint: true)
        : null,
    default => null,
};
```

`Target($type, $id, $relationship, $isRelationshipEndpoint)` is the router-agnostic
endpoint identifier — `hasId()` / `hasRelationship()` distinguish the shapes. Once
built, the router attaches it before delegating — `$handler->handle($request->withAttribute(Target::class, $target))`
(see [`PathPrefixRouter`](../examples/music-catalog/src/Http/PathPrefixRouter.php)).
In a real app your framework's router builds this; the library only needs the
attribute present. The example's router is a stand-in, not a routing engine.

## Step 5 — wire the server

The [`Server`](server.md) is the configuration root for one API version. It holds the
Resource registry, the PSR-17 factories, the ordered middleware list, and the
handler. It is an immutable value — every `with…()` / `register()` returns a new
instance — and is itself a PSR-15 `RequestHandlerInterface`. From the example's
[`bootstrap.php`](../examples/music-catalog/src/bootstrap.php) (elided to the albums
slice):

```php
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();

$base = Server::make()
    ->withBaseUri('https://music.example')
    ->withPsr17($psr17, $psr17)
    ->register(AlbumResource::class);

$server = $base
    ->withMiddleware([
        new ErrorHandlerMiddleware($base),
        new ContentNegotiationMiddleware(),
        new RequestBodyParsingMiddleware(),
        new PathPrefixRouter($base),
    ])
    ->withHandler(new MusicCatalogHandler($repository));
```

`register()` reads the static `$type` **without instantiating** the Resource, so a
Resource with constructor dependencies works (instances are built lazily on first
lookup). The middleware list runs outermost-first: the error handler wraps everything
(so any thrown JSON:API exception becomes an error document), content negotiation
enforces the media type, body parsing decodes the request, and the router resolves
the `Target` just before the handler runs. See [Server](server.md) for the full
configurator surface and [Middleware](middleware.md) for the ordering rationale.

## Step 6 — handle a request

`Server` is a request handler, so dispatching is a single call. Pass it the PSR-7
request your framework hands you:

```php
$response = $server->handle($request); // a PSR-7 ResponseInterface
```

## Three worked outcomes

Each outcome below is asserted by
[`GettingStartedTest`](../examples/music-catalog/tests/GettingStartedTest.php).

### `GET /albums/1` → `200`

A request with `Accept: application/vnd.api+json` resolves to a single-resource
`FetchResourceOperation`, finds the album, and renders a spec-compliant document:

```json
{
    "jsonapi": { "version": "1.1" },
    "data": {
        "type": "albums",
        "id": "1",
        "attributes": { "title": "OK Computer", "explicit": false }
    }
}
```

### `POST /albums` → `201`

A full create envelope — a `data` member of `type` `albums` with `attributes`, sent
with both `Accept` and `Content-Type: application/vnd.api+json`:

```json
{
    "data": {
        "type": "albums",
        "attributes": { "title": "In Rainbows", "explicit": false }
    }
}
```

No client id is supplied, and `AlbumResource` declares `Id::make()->uuid()->generated()`,
so the app mints a UUID and echoes it in `Location`
(`https://music.example/albums/{id}`). The response is `201` carrying the created
resource. Because reads and writes share one store, the new album is immediately
fetchable through the same server.

### `GET /albums/999` → `404`

A request for a missing resource renders a `404` error document, because the handler
returns `ErrorResponse::fromException(new ResourceNotFound())` and the error-handler
middleware encodes it. The body is a spec-compliant errors document.

## Where to go next

- [Concepts](concepts.md) — the JSON:API document model these pieces produce.
- [Architecture](architecture.md) — how a request flows through the library.
- [Resources](resources.md) and [Fields](fields.md) — the type model and the field DSL.
- [Operations](operations.md) and [Responses](responses.md) — the handler's input and output.
- [Server](server.md) and [Middleware](middleware.md) — wiring an API and the PSR-15 suite.
- [Documentation index](index.md) — the full page map.
