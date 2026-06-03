# Getting started

This page builds a small but complete JSON:API endpoint — fetching and creating
`articles` — from an empty project. By the end you will have a running PSR-15
application that serves spec-compliant responses, and you will know which pieces
the library provides and which you are expected to wire up yourself.

Every code block on this page is taken from a test that runs on each CI build
([`tests/Docs/GettingStartedExampleTest.php`](../tests/Docs/GettingStartedExampleTest.php)),
so the example cannot drift from the code.

## Installation

> Not yet published to Packagist. Once the first `0.x` release is cut:

```bash
composer require haddowg/json-api
```

The library targets PHP 8.3+ and speaks [PSR-7](https://www.php-fig.org/psr/psr-7/)
v2 and [PSR-15](https://www.php-fig.org/psr/psr-15/) throughout. It does **not**
bundle a PSR-7 implementation, so install one — the examples here use
[`nyholm/psr7`](https://github.com/Nyholm/psr7):

```bash
composer require nyholm/psr7
```

## The pieces you provide

The library is framework- and storage-agnostic. To serve a resource type you
supply:

1. **A domain model** — any object or array; the library never dictates its shape.
2. **A Resource class** — an [`AbstractResource`](resources.md) subclass declaring
   the type's fields. One declaration drives both serialization (model → JSON:API)
   and hydration (request → model).
3. **An operation handler** — your application logic, expressed as a function from
   a parsed [operation](server.md#operations) to a [response value object](responses.md).
4. **A router** — mapping a URL to a JSON:API [`Target`](server.md#routing-and-targets).
   Core ships no router; this is your framework's job. The example uses a tiny
   path-prefix stand-in.

The library provides everything between the HTTP message and your handler:
content negotiation, request parsing, sparse fieldsets, includes, error
rendering, and response encoding.

## The domain model

A plain object — no base class, no ORM, no annotations:

```php
final class Article
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public string $body = '',
    ) {}
}
```

A trivial in-memory store stands in for a database:

```php
final class ArticleRepository
{
    /** @var array<int|string, Article> */
    private array $articles;

    public function __construct()
    {
        $this->articles = [
            '1' => new Article('1', 'JSON:API in PHP', 'A worked example.'),
            '2' => new Article('2', 'Second article', 'Another one.'),
        ];
    }

    public function find(string $id): ?Article
    {
        return $this->articles[$id] ?? null;
    }

    /** @return list<Article> */
    public function all(): array
    {
        return \array_values($this->articles);
    }

    public function save(Article $article): void
    {
        $this->articles[$article->id] = $article;
    }
}
```

## The Resource class

A Resource class declares the resource type's fields. This one list is the single source
of truth for both directions: it tells the serializer how to render an `Article`
and the hydrator how to fill one from a request body. See [Resources](resources.md)
and [Fields](fields.md) for the full surface.

```php
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(255)->sortable(),
            Str::make('body')->required(),
        ];
    }
}
```

## The operation handler

Your handler receives a parsed [`JsonApiOperationInterface`](server.md#operations) and
returns one of the [response value objects](responses.md). It never touches PSR-7
directly — the framing is done for you. Dispatch on the concrete operation type
with `match (true)`; the type system narrows each branch:

```php
use haddowg\JsonApi\Exception\ResourceNotFound;
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

        $articles = $server->serializerFor('articles');

        return match (true) {
            $operation instanceof FetchResourceOperation => $operation->target()->hasId()
                ? $this->show((string) $operation->target()->id, $server)
                : DataResponse::fromCollection($this->repository->all(), $articles),
            $operation instanceof CreateResourceOperation => $this->create($operation, $server),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    private function show(string $id, Server $server): DataResponse|ErrorResponse
    {
        $article = $this->repository->find($id);
        if ($article === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        return DataResponse::fromResource($article, $server->serializerFor('articles'));
    }

    private function create(CreateResourceOperation $operation, Server $server): DataResponse
    {
        $article = $server->hydratorFor('articles')->hydrate($operation->body(), new Article());
        \assert($article instanceof Article);

        $this->repository->save($article);

        return DataResponse::fromResource($article, $server->serializerFor('articles'));
    }
}
```

The handler reaches the registered Resource class through `$operation->context()->server`.
That property is typed as the minimal `ServerInterface`; narrow it to the concrete
`Server` (with `assert` / `instanceof`) to reach `serializerFor()` /
`hydratorFor()`.

## The router

Core deliberately ships no router — mapping a URL to a resource is your
framework's concern. A router's only job here is to attach a
[`Target`](server.md#routing-and-targets) to the request as an attribute keyed by
`Target::class`; the operations adapter reads it to pick the operation. A
hand-rolled path-prefix stand-in:

```php
use haddowg\JsonApi\Operation\Target;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ArticleRouter implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = \trim($request->getUri()->getPath(), '/');
        $segments = $path === '' ? [] : \explode('/', $path);

        if (($segments[0] ?? null) === 'articles') {
            $request = $request->withAttribute(
                Target::class,
                new Target('articles', $segments[1] ?? null),
            );
        }

        return $handler->handle($request);
    }
}
```

## Wiring the server

The [`Server`](server.md) is the configuration root for one API version. It holds
the Resource registry, the PSR-17 factories, the ordered middleware list, and the
handler. It is an immutable value (every `with…()` returns a new instance) and is
itself a PSR-15 `RequestHandlerInterface`:

```php
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();
$repository = new ArticleRepository();

$base = Server::make()
    ->withBaseUri('https://example.test')
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class);

$server = $base
    ->withMiddleware([
        new ErrorHandlerMiddleware($base),
        new ContentNegotiationMiddleware(),
        new RequestBodyParsingMiddleware(),
        new ArticleRouter(),
    ])
    ->withHandler(new ArticleHandler($repository));
```

The middleware list runs outermost-first: the error handler wraps everything (so
any thrown JSON:API exception becomes an error document), content negotiation
enforces the media type, body parsing decodes the request, and the router resolves
the target just before the handler runs. See [Middleware](middleware.md) for the
ordering rationale.

## Handling a request

`Server` is a request handler, so dispatching is a single call. Pass it the PSR-7
request your framework hands you:

```php
$response = $server->handle($request); // $response is a PSR-7 ResponseInterface
```

A `GET https://example.test/articles/1` with `Accept: application/vnd.api+json`
produces:

```json
{
    "jsonapi": { "version": "1.1" },
    "data": {
        "type": "articles",
        "id": "1",
        "attributes": { "title": "JSON:API in PHP", "body": "A worked example." }
    }
}
```

A `POST https://example.test/articles` with a JSON:API body creates a resource.
The Resource class hydrates the new `Article`, the handler saves it, and the response
echoes the created resource (with the server-generated `id`):

```json
{
    "data": {
        "type": "articles",
        "attributes": { "title": "A new article", "body": "Created over HTTP." }
    }
}
```

A request for a missing resource (`GET /articles/999`) renders a `404` error
document, because the handler returns `ErrorResponse::fromException(new ResourceNotFound())`
and the error handler middleware encodes it.

## Where to go next

- [Resources](resources.md) — the recommended way to declare a resource type.
- [Fields](fields.md) — every field type and its fluent options.
- [Responses](responses.md) — the five response value objects and their `with…` chaining.
- [Server](server.md) — configuration, routing, operations, multi-version APIs.
- [Middleware](middleware.md) — the PSR-15 suite and recommended order.
- [Concepts](concepts.md) — the JSON:API document model as this package represents it.
- [Documentation index](README.md) — the full page list.
