# Middleware

`haddowg/json-api` ships a small [PSR-15](https://www.php-fig.org/psr/psr-15/)
middleware suite under `haddowg\JsonApi\Middleware\` that implements the JSON:API
request lifecycle: content negotiation, request-body parsing, and error
rendering. Middleware are **per-server** — each [`Server`](server.md) holds its
own ordered middleware list and is itself a `RequestHandlerInterface`, folding
that list over its inner handler when you call `handle()`. There is no global
middleware registry and no select-server middleware; choosing which server runs
is the routing layer's job.

This page covers the suite, how to order it, and the optional validation
middleware. For the full ordering rationale, see
[Middleware order](middleware-order.md).

## The suite

| Middleware | Constructor | Role |
|---|---|---|
| `ErrorHandlerMiddleware` | `(ServerInterface $server, bool $debug = false, ?LoggerInterface $logger = null)` | Outermost. Turns any thrown exception into an error document. |
| `ContentNegotiationMiddleware` | `(string ...$supportedExtensions)` | Validates the JSON:API media type, negotiates extensions, validates query params. |
| `RequestBodyParsingMiddleware` | `()` | Forces an early JSON decode so malformed bodies fail at the edge. |
| `RequestValidationMiddleware` _(optional, dev/CI)_ | `(ServerInterface $server, DocumentValidator $validator)` | Validates the request body against the JSON:API JSON Schema. |
| `ResponseValidationMiddleware` _(optional, dev/CI)_ | `(ServerInterface $server, DocumentValidator $validator, bool $throwOnViolation = true, ?LoggerInterface $logger = null)` | Validates the rendered response document. |
| `JsonApiMiddleware` | `(ServerInterface $server, bool $debug = false, ?LoggerInterface $logger = null, string ...$supportedExtensions)` | Aggregate wiring the core three in the recommended order. |

The three core middleware (`ErrorHandlerMiddleware`,
`ContentNegotiationMiddleware`, `RequestBodyParsingMiddleware`) are all you need
for a spec-compliant endpoint. The two validation middleware are opt-in and
intended for dev/CI; see [Validation](validation.md).

## Recommended order

Outermost first — the outermost middleware wraps every middleware below it:

1. **`ErrorHandlerMiddleware`** — catches every `JsonApiExceptionInterface` (rendered with
   its own status and errors) and any other `\Throwable` (rendered as a 500), and
   emits the resulting [`ErrorResponse`](errors.md) as a PSR-7 response. It must
   be outermost so it also catches exceptions thrown by negotiation and body
   parsing, not only by the handler. A successful PSR-7 response from below passes
   through unchanged.
2. **`ContentNegotiationMiddleware`** — validates the `Content-Type` / `Accept`
   media-type parameters and negotiates extensions (415 / 406), then validates
   query parameters (400). Runs before body parsing so a media-type mismatch is
   rejected before any body work. See [Content negotiation](content-negotiation.md).
3. **`RequestBodyParsingMiddleware`** — forces an early JSON decode when a body is
   present, so malformed JSON surfaces as a 400 here rather than inside the
   handler. A bodyless request (GET, empty body) passes through untouched.
4. **Handler** — innermost. The recommended handler is an `OperationHandlerInterface`
   wrapped in `Psr7ToOperationHandlerAdapter`, which turns the PSR-7 request into
   the matching operation and renders the returned [response value
   object](responses.md). Any `RequestHandlerInterface` works.

The order is a **recommendation, not a constraint** — a server can be built with
any middleware list. The error handler being outermost is the one firm
recommendation, because everything else can throw.

```php
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware;
use haddowg\JsonApi\Server\Server;

$base = Server::make()
    ->withBaseUri('https://example.test')
    ->withPsr17($psr17, $psr17)
    ->register(ArticleResource::class);

$server = $base
    ->withMiddleware([
        new ErrorHandlerMiddleware($base),
        new ContentNegotiationMiddleware(),
        new RequestBodyParsingMiddleware(),
        // your router middleware, then the handler
    ])
    ->withHandler($handler);
```

`ErrorHandlerMiddleware` takes the `Server` so it can reach the PSR-17 factories
to build error responses. Because `Server` is immutable, the error handler is
constructed from the pre-middleware `$base` and the finished server is the value
you call `handle()` on.

## The aggregate

`JsonApiMiddleware` composes the core three into a single `MiddlewareInterface`
in the recommended order, for consumers who would rather not manage ordering:

```php
use haddowg\JsonApi\Middleware\JsonApiMiddleware;

$server = $base
    ->withMiddleware([
        new JsonApiMiddleware($base, debug: false),
        // your router middleware
    ])
    ->withHandler($handler);
```

It accepts the same `$debug` / `$logger` / `$supportedExtensions` arguments as the
underlying middleware. The three building blocks remain independently
constructable; the aggregate only wires them.

## How the parsed request flows

The first JSON:API middleware to run wraps the incoming PSR-7 request in a
`Request\JsonApiRequest` and passes **that instance** down the chain:

```php
$jsonApiRequest = $request instanceof JsonApiRequestInterface
    ? $request
    : new JsonApiRequest($request);

return $handler->handle($jsonApiRequest);
```

`JsonApiRequest` *is* a `ServerRequestInterface`, so this is transparent to
generic PSR-15 middleware, and the wrap is idempotent — once one middleware has
wrapped the request, the others see a `JsonApiRequestInterface` and leave it
alone. Downstream middleware, the handler, and the operation adapter therefore
share one memoized parse and never re-parse query params or the body. There is
**no request attribute** for the parsed request; the only routing attribute the
suite reads is `Operation\Target::class`.

## Optional validation middleware (dev/CI)

`RequestValidationMiddleware` and `ResponseValidationMiddleware` add JSON Schema
validation of the request and response documents. They are **per-server opt-in**:
add them only on the servers where you want them (typically a dev/CI server, not
production). Both take a `Server` and a `DocumentValidator`, and the
`DocumentValidator` requires the optional `opis/json-schema` package — so wiring
fails fast if it is absent.

- `RequestValidationMiddleware` runs **after** body parsing (it needs the parsed
  body) and **before** the handler; a violation throws `RequestBodyInvalidJsonApi`
  (400). Bodyless requests pass through.
- `ResponseValidationMiddleware` sits just **inside** the error handler and
  outside negotiation/body-parsing, so its check runs last on the unwind, against
  the fully rendered PSR-7 response. By default a violation throws
  `ResponseBodyInvalidJsonApi` (500) — a failing response is a server bug, so it
  is loud in dev/CI; pass `$throwOnViolation: false` to downgrade to logging the
  violations and passing the response through (a production-soak mode).

```php
use haddowg\JsonApi\Middleware\RequestValidationMiddleware;
use haddowg\JsonApi\Middleware\ResponseValidationMiddleware;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApi\Validation\VendoredSchemaProvider;

$validator = new DocumentValidator(new VendoredSchemaProvider());

$devServer = $base
    ->withMiddleware([
        new ErrorHandlerMiddleware($base),
        new ResponseValidationMiddleware($base, $validator),
        new ContentNegotiationMiddleware(),
        new RequestBodyParsingMiddleware(),
        new RequestValidationMiddleware($base, $validator),
        // your router middleware
    ])
    ->withHandler($handler);
```

See [Validation](validation.md) for the schema model and the per-resource schema
compiler these middleware compose in.

## Composing middleware by hand

If you are not using `Server`, the `@internal Middleware\Internal\MiddlewareHandler`
shows the composition shape: it wraps one middleware plus the next handler into a
`RequestHandlerInterface`, so nesting handlers builds the pipeline outermost-first.
Most consumers never touch it — `Server::withMiddleware()` and `JsonApiMiddleware`
do the wiring.

## Related pages

- [Middleware order](middleware-order.md) — the full ordering rationale.
- [Server](server.md) — the per-server middleware list and how `handle()` runs it.
- [Content negotiation](content-negotiation.md) — what the negotiation middleware checks.
- [Validation](validation.md) — the optional JSON Schema middleware.
- [Errors](errors.md) — what the error handler renders.
