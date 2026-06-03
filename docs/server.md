# Server

The `Server\Server` is the configuration root for one API version. It holds the
Resource registry, the profile registry, the PSR-17 factories, the document-level
defaults (base URI, JSON:API version, `jsonapi.meta`, `json_encode` flags, the
default paginator), the ordered middleware list, and the inner handler. It is an
immutable value — `Server::make()` returns an empty server and every `with…()` /
`register()` returns a *new* instance, so a configured server can be shared and
specialised without surprise mutation. It is also a PSR-15
`RequestHandlerInterface`, so handling a request is a single `handle()` call.

```php
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();

$server = Server::make()
    ->withBaseUri('https://example.test')
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class)
    ->register(AuthorResource::class)
    ->withMiddleware([/* … */])
    ->withHandler(new ArticleHandler($repository));
```

`Server` implements the minimal `Server\ServerInterface` (the contract the
[response value objects](responses.md) read to render), plus the Resource registry
and the PSR-15 entry point. The response layer only ever sees `ServerInterface`;
your handler narrows to the concrete `Server` when it needs `serializerFor()` /
`hydratorFor()`.

## Configuration

Every configurator returns a new `Server`. Apart from `register()` and
`withProfile()` (which add to the respective registry), each `with…()` replaces a
single setting.

| Method | Sets |
|---|---|
| `withBaseUri(string)` | The base URI prepended to generated links (default `''`). |
| `withVersion(string)` | The `jsonapi.version` member (default `1.1`). |
| `withDefaultMeta(array)` | The default `jsonapi.meta` object. |
| `withEncodeOptions(int)` | Flags passed to `json_encode()` when rendering (e.g. `JSON_PRETTY_PRINT`). |
| `withDefaultPaginator(?PaginatorInterface)` | The fallback [paginator](pagination.md) for collections. |
| `withPsr17(ResponseFactoryInterface, StreamFactoryInterface)` | The PSR-17 factories used to emit the PSR-7 response. |
| `register(string $resource, ?string $serializer, ?string $hydrator)` | Registers a [Resource class](resources.md) (class-string) for its declared `$type`, with optional serializer/hydrator [overrides](serializers.md). |
| `registerSerializerHydrator(string $type, ?string $serializer, ?string $hydrator)` | Registers a [bare serializer + hydrator pair](#bare-serializer--hydrator-pairs) (class-strings) under an explicit `$type`, with no Resource class. |
| `withContainer(ContainerInterface\|callable)` | Sets the [lazy instantiation factory](#lazy-instantiation-and-containers) used to build registered classes. |
| `withProfile(ProfileInterface)` | Registers a [profile](profiles.md). |
| `withMiddleware(list<MiddlewareInterface>)` | Replaces the ordered [middleware](middleware.md) list. |
| `withHandler(OperationHandlerInterface\|RequestHandlerInterface)` | Sets the inner handler. |

The matching accessors read the configuration back: `baseUri()`,
`jsonApiVersion()`, `defaultMeta()`, `encodeOptions()`, `defaultPaginator()`,
`profiles()`, `resources()`, plus the registry shortcuts `serializerFor(string)` /
`hydratorFor(string)` / `hasSerializerFor(string)` (each resolving an override
ahead of the Resource class). `responseFactory()` / `streamFactory()` throw a
`\LogicException` if `withPsr17()` was never called — emitting a response needs
both factories.

> `register()` and `withProfile()` clone the underlying registries before
> mutating them, so registering on a derived server never leaks back into the
> parent. Registering two Resource classes for the same `$type`, or two profiles
> with the same URI, is a wiring error (a `\LogicException`), never a JSON:API
> error document.

## Lazy instantiation and containers

`register()` takes **class-strings** and the registry reads the resource's
`static $type` to key the entry **without** instantiating the class. Instances are
built **lazily on first lookup** and cached, so registering a server is cheap and a
Resource whose constructor has side effects (or dependencies) is not constructed
until it is actually used.

By default the registry builds each class with plain `new $class()`, which requires
a no-argument constructor. To build Resources, serializers and hydrators with
dependencies, give the server a factory with `withContainer()`. It accepts either a
PSR-11 `\Psr\Container\ContainerInterface` or any `callable(class-string): object`;
both are normalised internally to a single closure:

```php
// PSR-11 container — the registry calls $container->get(SomeResource::class).
$server = Server::make()
    ->withContainer($container)
    ->register(ArticleResource::class);

// Or any callable that maps a class-string to an instance.
$server = Server::make()
    ->withContainer(fn(string $class) => $factory->make($class))
    ->register(ArticleResource::class);
```

`withContainer()` is order-independent — calling it before or after `register()` is
equivalent, because lookups are lazy and the factory lives on the registry. Like
every other configurator it clones, so it never leaks into a parent server.

> The factory must return an instance of the requested concern
> (`AbstractResource` / `SerializerInterface` / `HydratorInterface`); a wrong-type
> return is a wiring error and throws a `\LogicException` on lookup. Prefer a
> factory that hands out **fresh** instances: the registry injects itself as the
> relationship serializer-resolver on every `resourceFor()` lookup, so a shared
> singleton handed to two servers would have its resolver overwritten per call.

## Bare serializer + hydrator pairs

For a type that has no field-driven [Resource](resources.md) class, register a
serializer and/or hydrator directly under an explicit `$type` with
`registerSerializerHydrator()`. A bare serializer/hydrator exposes its type only via
the instance method `getType($object)`, so it cannot key itself — the explicit
`$type` argument is the key:

```php
$server = Server::make()
    ->registerSerializerHydrator(
        'articles',
        serializer: ArticleSerializer::class,
        hydrator: ArticleHydrator::class,
    );
```

A bare pair has **no Resource fallback**: `serializerFor()`/`hydratorFor()` resolve
only the explicitly registered class, and any `resourceFor()` lookup (or a lookup
for the concern you did not register) throws `NoResourceRegistered`. Bare pairs are
built through the same lazy resolver as Resource classes, so `withContainer()`
applies to them too.

## Handling a request

`Server::handle()` folds the configured middleware list over the inner handler —
each middleware wraps the next, outermost first — and dispatches the PSR-7
request through the resulting chain:

```php
$response = $server->handle($request); // PSR-7 ResponseInterface
```

The inner handler is whatever you passed to `withHandler()`. There are two
accepted shapes:

- An **`OperationHandlerInterface`** (the recommended consumer surface, below). The server
  wraps it in `Psr7ToOperationHandlerAdapter` automatically — the adapter turns
  the request into an operation, calls your handler, and encodes the returned
  [response value object](responses.md) to PSR-7.
- A **bare PSR-15 `RequestHandlerInterface`**, also accepted directly (for full
  control of the response) when you want to own the request/response framing yourself.

Calling `handle()` with no handler configured throws a `\LogicException`.

## Operations

An operation is the PSR-7-decoupled description of one JSON:API request: what
endpoint it targets, the query parameters in effect, and the ambient context. The
`Operation\JsonApiOperationInterface` interface is the common contract —

```php
interface JsonApiOperationInterface
{
    public function target(): Target;
    public function queryParameters(): QueryParameters;
    public function context(): OperationContext;
}
```

— and there is one `final readonly` class per HTTP verb × endpoint shape, each
carrying exactly the data it needs:

| Operation | Endpoint | Body? |
|---|---|---|
| `FetchResourceOperation` | `GET /articles` or `GET /articles/1` | — |
| `FetchRelatedOperation` | `GET /articles/1/author` | — |
| `FetchRelationshipOperation` | `GET /articles/1/relationships/author` | — |
| `CreateResourceOperation` | `POST /articles` | yes |
| `UpdateResourceOperation` | `PATCH /articles/1` | yes |
| `DeleteResourceOperation` | `DELETE /articles/1` | — |
| `AddToRelationshipOperation` | `POST /articles/1/relationships/tags` | yes |
| `UpdateRelationshipOperation` | `PATCH /articles/1/relationships/author` | yes |
| `RemoveFromRelationshipOperation` | `DELETE /articles/1/relationships/tags` | yes |

The five body-carrying operations expose a `body(): JsonApiRequestInterface` in
addition to the three interface methods; the read-only ones do not. Because each
verb is its own type, a handler dispatches with `match (true)` and the type system
narrows each branch.

### The handler

`Operation\OperationHandlerInterface` is the recommended consumer extension point:

```php
public function handle(
    JsonApiOperationInterface $operation,
): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse;
```

A handler receives the parsed operation and returns one of the five
[response value objects](responses.md). It is PSR-7-free; reach the originating
request through `context()->httpRequest()` only when you genuinely need it.

```php
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Server\Server;

final class ArticleHandler implements OperationHandlerInterface
{
    public function __construct(private readonly ArticleRepository $repository) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        \assert($server instanceof Server);

        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation, $server),
            $operation instanceof CreateResourceOperation => $this->create($operation, $server),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    // …
}
```

### Context and query parameters

`Operation\OperationContext` carries the ambient `server` (public, typed as the
minimal `ServerInterface` — narrow it to `Server` to reach `serializerFor()` /
`hydratorFor()`) and an **optional** originating HTTP request. `httpRequest()`
returns `null` for a programmatically-dispatched operation, so a handler that
reaches for the raw PSR-7 message must null-check.

`Operation\QueryParameters` is the parsed, spec-shaped projection of the JSON:API
query-param families — `fields` (sparse fieldsets keyed by type), `includes`
(relationship paths), `sort`, `filter`, and `pagination`. It is a leaf value
object (the readonly property is the accessor) and is built from a request with
`QueryParameters::fromRequest($request)`.

## Routing and targets

`Operation\Target` identifies the endpoint independent of PSR-7: the primary
`type`, an optional resource `id`, an optional `relationship` name, and an
`isRelationshipEndpoint` flag distinguishing the linkage endpoint
(`/articles/1/relationships/author`) from the related-resource endpoint
(`/articles/1/author`).

**Core ships no router** — mapping a URL to a `Target` is your framework's job.
The contract is simply that something upstream attaches a `Target` to the request
as an attribute keyed by `Target::class`:

```php
$request = $request->withAttribute(
    Target::class,
    new Target('articles', $id),
);
```

`Psr7ToOperationHandlerAdapter` reads that attribute and delegates to the public,
stateless `Operation\OperationFactory`, which picks the operation from a fixed
**HTTP-method × target-shape** dispatch table:

| Method | No relationship | `…/relationships/x` | `…/x` (related) |
|---|---|---|---|
| GET | `FetchResourceOperation` | `FetchRelationshipOperation` | `FetchRelatedOperation` |
| POST | `CreateResourceOperation` | `AddToRelationshipOperation` | — |
| PATCH | `UpdateResourceOperation` | `UpdateRelationshipOperation` | — |
| DELETE | `DeleteResourceOperation` | `RemoveFromRelationshipOperation` | — |

`OperationFactory::fromRequest(JsonApiRequestInterface $request, Target $target,
OperationContext $context): JsonApiOperationInterface` is the single source of
truth for this decision, so a framework integration can reuse it directly rather
than re-implementing the table. It takes the already-parsed request (it builds the
`QueryParameters` and never re-wraps) and the context explicitly (each caller keeps
its own choice of HTTP request behind the context). A missing `Target` is the
adapter's concern, not the factory's: if routing fails to attach one, the adapter
renders a `500` error document (it does not throw — the PSR-15 contract still
yields a JSON:API response). An unhandled method surfaces as an `ApplicationError`
(also a `500`) thrown from the factory. The
[getting-started guide](getting-started.md#the-router) shows a tiny hand-rolled
path-prefix router that supplies the `Target`.

## dispatch()

`Server::dispatch(JsonApiOperationInterface)` invokes the configured `OperationHandlerInterface`
**directly**, bypassing the PSR-15 chain. It returns the response value object
unrendered, which makes it the natural entry point for programmatic calls and
integration tests:

```php
$response = $server->dispatch($operation); // a response value object, not PSR-7
```

`dispatch()` requires the inner handler to be an `OperationHandlerInterface` (a bare PSR-15
handler throws a `\LogicException`). Build operations for it with the
[`JsonApiOperationBuilder`](testing.md) test utility.

## Multiple servers / API versioning

One `Server` describes one API version. To serve several versions side by side,
configure one server per version and pick between them upstream — that selection
is routing, and (as with target resolution) it lives outside core. A tiny
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

$response = $dispatcher->handle($request);
```

Each `Server` keeps its own registry, defaults, and middleware list, so versions
evolve independently — a type registered (or a default changed) on `v2` never
affects `v1`.

## Related pages

- [Getting started](getting-started.md) — the full server wiring in context.
- [Resources](resources.md) — what `register()` takes and how the registry resolves.
- [Middleware](middleware.md) — the PSR-15 suite and recommended order.
- [Responses](responses.md) — the value objects a handler returns.
- [Testing](testing.md) — `JsonApiOperationBuilder` for `dispatch()` tests.
