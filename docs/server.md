# The Server: configuring an API

`Server\Server` is the configuration root for one API version. You build it
fluently — a base URI, the PSR-17 factories, a default paginator, the recognised
[profiles](profiles.md), your registered types, the [middleware](middleware.md)
chain, and the inner handler — and you get back a value you can hand to a
framework or call directly. This page covers how that value is assembled, how it
resolves a type's serializer and hydrator, and the two ways to drive it.

If this is your first server, read [getting started](getting-started.md) first —
it walks the whole wiring in context. (For the pre-1.0 and install caveats, see
[the index](index.md).)

## An immutable configuration root

`Server::make()` returns an empty server, and **every** `with…()` / `register…()`
call returns a *new* instance — the underlying registries are cloned before they
are touched, so a configured server is a shareable value and a derived server
never leaks back into its parent. You can keep a base server around and specialise
it per request, per test, or per version without surprise mutation.

At a glance, the common path is three steps: **register** your types, **set** the
middleware list and inner handler, then **drive** the server — `handle()` for an
HTTP request or [`dispatch()`](#dispatch-the-no-psr-7-path) for a programmatic call.
The example app's [`bootstrap.php`](../examples/music-catalog/src/bootstrap.php) is
the single source of truth for a full assembly. It builds the configuration root,
then derives the runnable server from it:

```php
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();

$base = Server::make()
    ->withBaseUri('https://music.example')
    ->withPsr17($psr17, $psr17)
    ->withDefaultPaginator(PagePaginator::make()->withDefaultPerPage(10))
    ->withProfile(new TimestampProfile())
    ->withProfile(new CursorPaginationProfile())
    ->register(ArtistResource::class)
    ->register(AlbumResource::class)
    ->register(TrackResource::class, serializer: TrackSerializer::class)
    ->register(PlaylistResource::class, hydrator: PlaylistHydrator::class)
    ->register(UserResource::class)
    ->register(FavoriteResource::class)
    ->register(LibraryResource::class)
    ->registerSerializerHydrator('charts', serializer: ChartSerializer::class);

$server = $base
    ->withMiddleware([
        new ErrorHandlerMiddleware($base, $debug),
        new ContentNegotiationMiddleware(),
        new RequestBodyParsingMiddleware(),
        new PathPrefixRouter($base),
    ])
    ->withHandler(new MusicCatalogHandler($repository));
```

`Server` implements `Server\ResolvingServerInterface`, which extends the minimal
`Server\ServerInterface` render contract (the surface the [response value
objects](responses.md) read) with the type-keyed
[serializer](serializers.md)/[hydrator](hydrators.md) resolution an
[operation handler](operations.md) needs. It is also a PSR-15
`RequestHandlerInterface`, so [handling a request](#handling-a-request) is one
`handle()` call.

## The configurator surface

Each `with…()` replaces a single setting; `register`, `registerSerializerHydrator`,
and `withProfile` add to a registry. All of them return a new server.

| Method | Sets |
|---|---|
| `withBaseUri(string)` | The base URI prepended to generated [links](links-and-meta.md) (default `''`). |
| `withVersion(string)` | The `jsonapi.version` member (default `1.1`). |
| `withDefaultMeta(array)` | The default `jsonapi.meta` object. |
| `withEncodeOptions(int)` | Flags passed to `json_encode()` when rendering (e.g. `\JSON_PRETTY_PRINT`). |
| `withDefaultPaginator(?PaginatorInterface)` | The fallback [paginator](pagination.md) for collections. |
| `withMaxIncludeDepth(?int)` | The default [maximum include depth](sparse-fieldsets-and-includes.md#maximum-include-depth) (hops from the primary resource). `null` (the default) or `<= 0` means unlimited; a resource can override it. |
| `withPsr17(ResponseFactoryInterface, StreamFactoryInterface)` | The PSR-17 factories used to emit the PSR-7 response. |
| `withContainer(ContainerInterface\|callable)` | The [lazy instantiation factory](#lazy-instantiation-and-containers) used to build registered classes. |
| `withRelationshipLoadState(?RelationshipLoadStateInterface)` | The [load-state predicate](#relationship-load-state) relations consult for `linkageOnlyWhenLoaded()`. |
| `register(string $resource, ?string $serializer, ?string $hydrator)` | Registers a [Resource class](resources.md) for its declared `$type`, with optional serializer/hydrator [overrides](capability-composition.md). |
| `registerSerializerHydrator(string $type, ?string $serializer, ?string $hydrator)` | Registers a [bare serializer + hydrator pair](capability-composition.md) under an explicit `$type`, no Resource. |
| `withProfile(ProfileInterface)` | Registers a [profile](profiles.md). |
| `withMiddleware(list<MiddlewareInterface>)` | Replaces the ordered [middleware](middleware.md) list. |
| `withHandler(OperationHandlerInterface\|RequestHandlerInterface)` | Sets the inner [handler](operations.md). |

The matching accessors read the configuration back:

| Accessor | Returns |
|---|---|
| `baseUri()` | The configured base URI. |
| `jsonApiVersion()` | The `jsonapi.version` member. |
| `defaultMeta()` | The default `jsonapi.meta` array. |
| `encodeOptions()` | The `json_encode()` flags. |
| `defaultPaginator()` | The fallback paginator, or `null`. |
| `profiles()` | The `ProfileRegistry`. |
| `responseFactory()` / `streamFactory()` | The PSR-17 factories — each **throws** a `\LogicException` if `withPsr17()` was never called. |
| `serializerFor(string)` / `hasSerializerFor(string)` | The serializer for a type (resolving an override ahead of the Resource), or whether one exists. |
| `hydratorFor(string)` / `hasHydratorFor(string)` | The hydrator for a type, or whether one exists. |
| `resourceFor(string)` / `hasResourceFor(string)` | The `AbstractResource` for a type, or whether one exists. |
| `relationshipLoadState()` | The injected load-state predicate, or `null`. |

`serializerFor()` / `hydratorFor()` are the resolution surface your handler uses.
The [music-catalog handler](../examples/music-catalog/src/Handler/MusicCatalogHandler.php)
narrows the context's server to the concrete `Server` and reads them per type:

```php
$server = $this->server($operation->context());
$type = $operation->target()->type;
$serializer = $server->serializerFor($type);
// …
$entity = $server->hydratorFor($type)->hydrate(/* … */);
```

A handler that only needs resolution (not `resourceFor()` / `defaultPaginator()`)
can type-hint the `ResolvingServerInterface` and skip the downcast — see
[operations](operations.md).

## Registering types

`register()` and `registerSerializerHydrator()` are the two ways a type enters the
server, and they are the seam that decouples a type from `AbstractResource`:

- **`register(ResourceClass::class, serializer:, hydrator:)`** — a full
  field-driven [Resource](resources.md), keyed by its declared `$type`. The
  optional `serializer:` / `hydrator:` arguments [override](capability-composition.md)
  one concern while the Resource still supplies the other — in `bootstrap.php`,
  `tracks` overrides the serializer (a request-aware `TrackSerializer` wins for
  reads) and `playlists` overrides the hydrator (`PlaylistHydrator` wins for
  writes).
- **`registerSerializerHydrator('type', serializer:, hydrator:)`** — a bare pair
  under an explicit type-string, with **no Resource** (at least one of the two is
  required). The standalone read-only `charts` type registers only a serializer:

  ```php
  ->registerSerializerHydrator('charts', serializer: ChartSerializer::class);
  ```

  `charts` therefore has a serializer but no hydrator and no Resource, so
  `hasHydratorFor('charts')` is `false` and `resourceFor('charts')` throws
  `NoResourceRegistered`. It serves `GET /charts` end-to-end (the
  [`ChartReadTest`](../examples/music-catalog/tests/ChartReadTest.php) proves it)
  and the router exposes only `GET` for it.

[Composing a type from independent capabilities](capability-composition.md) is the
full story — read-only vs write-only types, override resolution order, and the
`NoResourceRegistered` boundary.

## Handling a request

`Server::handle()` folds the configured middleware list over the inner handler —
each middleware wraps the next, outermost first — and dispatches the PSR-7 request
through the resulting chain:

```php
$response = $server->handle($request); // PSR-7 ResponseInterface
```

The inner handler is whatever you passed to `withHandler()`. There are two accepted
shapes:

- An **`OperationHandlerInterface`** (the recommended surface). The server wraps it
  in `Psr7ToOperationHandlerAdapter` automatically — the adapter turns the request
  into an [operation](operations.md), calls your handler, and encodes the returned
  [response value object](responses.md) to PSR-7.
- A **bare PSR-15 `RequestHandlerInterface`**, accepted directly when you want to
  own the request/response framing yourself.

Calling `handle()` with no handler configured throws a `\LogicException`.

## dispatch(): the no-PSR-7 path

`Server::dispatch(JsonApiOperationInterface)` invokes the configured
`OperationHandlerInterface` **directly**, bypassing the middleware chain. It returns
the [response value object](responses.md) *unrendered*, which makes it the natural
entry point for programmatic calls, integration tests, and framework integrations
that own their own request lifecycle (the Symfony bundle dispatches this way):

```php
$response = $server->dispatch($operation); // a response value object, not PSR-7
```

`dispatch()` requires the inner handler to be an `OperationHandlerInterface` (a bare
PSR-15 handler throws a `\LogicException`). The same handler serves both paths: the
[music-catalog handler](../examples/music-catalog/src/Handler/MusicCatalogHandler.php)
reaches the originating request through `context()->httpRequest()`, which returns
`null` under `dispatch()`, so it falls back to a minimal request when there is no
HTTP message. Build operations for `dispatch()` with the
[`JsonApiOperationBuilder`](testing.md) test utility:

```php
$operation = JsonApiOperationBuilder::create('albums', $server)
    ->withAttribute('title', 'In Rainbows')
    ->build();
```

See [operations](operations.md) for the operation model, the `Target`/router seam,
and the handler contract.

## Lazy instantiation and containers

`register()` takes **class-strings** and the registry reads the resource's
`static $type` to key the entry **without** constructing the class. Instances are
built **lazily on first lookup** and cached, so registering a server is cheap and a
resource whose constructor has dependencies (or side effects) is not built until it
is actually used.

By default the registry builds each class with plain `new $class()`, which needs a
no-argument constructor. To build resources, serializers, and hydrators with
dependencies, give the server a factory through `withContainer()`. It accepts
either a PSR-11 `\Psr\Container\ContainerInterface` **or** any
`callable(class-string): object`; both are normalised internally to a single
closure:

```php
// PSR-11 container — the registry calls $container->get(ArtistResource::class).
$server = Server::make()
    ->withContainer($container)
    ->register(ArtistResource::class);

// Or any callable that maps a class-string to an instance.
$server = Server::make()
    ->withContainer(fn(string $class) => $factory->make($class))
    ->register(ArtistResource::class);
```

`withContainer()` is **order-independent** — calling it before or after
`register()` is equivalent, because lookups are lazy and the factory lives on the
registry. Like every other configurator it clones, so it never leaks into a parent
server.

> The factory must return an instance of the requested concern
> (`AbstractResource` / `SerializerInterface` / `HydratorInterface`); a wrong-type
> return is a wiring fault and throws a `\LogicException` on lookup. A PSR-11
> container that returns a non-object is caught the same way. Prefer a factory that
> hands out **fresh** instances: the registry injects itself as the relationship
> serializer-resolver when it first builds (and caches) an instance, so a shared
> singleton handed to two servers would have its resolver overwritten by whichever
> server's registry built it last.

## Relationship load state

`withRelationshipLoadState()` injects the storage-aware predicate relations consult
when they opt in via `RelationInterface::linkageOnlyWhenLoaded()` — it decides
whether a relation's linkage is already loaded and so cheap to emit, letting a lazy
to-many render links-only without forcing a fetch. Passing `null` (the default)
restores the standalone behaviour: every relation is treated as loaded and its
linkage data is emitted. See [relations](relations.md) for the policy and the
predicate contract.

## Wiring faults are exceptions, not error documents

A misconfigured server is a programming error, so the registry throws a
`\LogicException` rather than producing a JSON:API error document. The faults you
might hit:

| Fault | When |
|---|---|
| Duplicate type | Registering two Resource classes (or two bare pairs) for the same `$type`. |
| Empty `$type` | A Resource whose `static $type` is empty, or a bare pair under an empty type-string. |
| Empty bare pair | `registerSerializerHydrator()` with neither a serializer nor a hydrator. |
| Duplicate profile URI | Registering two [profiles](profiles.md) with the same URI. |
| Wrong-type factory return | A `withContainer()` factory returning the wrong concern (or a non-object). |
| Missing PSR-17 | `responseFactory()` / `streamFactory()` read before `withPsr17()`. |
| Missing handler | `handle()` or `dispatch()` with no `withHandler()`. |
| `dispatch()` on a PSR-15 handler | `dispatch()` when the inner handler is a bare `RequestHandlerInterface`. |

These are distinct from request-time JSON:API errors — those are rendered as
documents through the [error handler](errors-and-exceptions.md).

## Multiple servers / API versioning

One `Server` describes one API version. To serve several side by side, configure
one server per version and pick between them upstream — that selection is routing,
and (as with [target resolution](operations.md)) it lives outside core. A tiny
path-prefix dispatcher, with no framework involved:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class VersionDispatcher implements RequestHandlerInterface
{
    /** @param array<string, RequestHandlerInterface> $servers prefix => server */
    public function __construct(private array $servers) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = \trim($request->getUri()->getPath(), '/');

        foreach ($this->servers as $prefix => $server) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return $server->handle($request);
            }
        }

        throw new \RuntimeException('No API version matched the request path.');
    }
}

$dispatcher = new VersionDispatcher([
    'v1' => $serverV1,
    'v2' => $serverV2,
]);
```

Each `Server` keeps its own registry, defaults, and middleware list, so versions
evolve independently — a type registered (or a default changed) on `v2` never
affects `v1`. Because every configurator clones, `v2` can be *derived* from `v1`
(`$v1->withVersion('2.0')->register(NewResource::class)`) and the shared base is
never mutated.

## Next / See also

- [Operations and dispatch](operations.md) — the operation VOs, `Target`, the
  handler contract, and the PSR-7 adapter `handle()` uses.
- [Composing a type from independent capabilities](capability-composition.md) —
  `register()` overrides and standalone `registerSerializerHydrator()` in full.
- [Middleware](middleware.md) — the PSR-15 suite and the recommended order.
- [Responses](responses.md) — the value objects a handler returns.
- [Testing](testing.md) — `JsonApiOperationBuilder` for `dispatch()` tests.
