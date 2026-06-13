# The middleware suite and ordering

`haddowg/json-api` ships a small [PSR-15](https://www.php-fig.org/psr/psr-15/)
middleware suite under `haddowg\JsonApi\Middleware\` that implements the JSON:API
request lifecycle: error rendering, content negotiation, request-body parsing, and
(optionally) JSON-Schema validation. This page is the single reference for which
middleware exist, how to order them, and why the order is what it is. By the end
you will be able to assemble a compliant chain by hand or with the bundled
aggregate.

Middleware are **per-server**. Each [`Server`](server.md) holds its own ordered
middleware list and is itself a `RequestHandlerInterface`, folding that list over
its inner handler when you call `handle()`. There is no global middleware
registry, no select-server middleware, and no middleware that picks which server
runs — that is the routing layer's job.

## The suite

Six classes, with exact constructor signatures:

| Middleware | Constructor | Role |
|---|---|---|
| `ErrorHandlerMiddleware` | `(ServerInterface $server, bool $debug = false, ?LoggerInterface $logger = null)` | Outermost. Turns any thrown exception into an [error document](errors-and-exceptions.md). |
| `ContentNegotiationMiddleware` | `(string ...$supportedExtensions)` | Validates the JSON:API media type, negotiates extensions, validates query params. |
| `RequestBodyParsingMiddleware` | `()` | Forces an early JSON decode and checks the top-level member rules, so a malformed or non-conformant body fails at the edge. |
| `RequestValidationMiddleware` _(optional, dev/CI)_ | `(ServerInterface $server, DocumentValidator $validator)` | Validates the request body against the JSON:API JSON Schema. |
| `ResponseValidationMiddleware` _(optional, dev/CI)_ | `(ServerInterface $server, DocumentValidator $validator, bool $throwOnViolation = true, ?LoggerInterface $logger = null)` | Validates the rendered response document. |
| `JsonApiMiddleware` | `(ServerInterface $server, bool $debug = false, ?LoggerInterface $logger = null, string ...$supportedExtensions)` | Aggregate wiring the three core middleware in the recommended order. |

The three core middleware — `ErrorHandlerMiddleware`,
`ContentNegotiationMiddleware`, `RequestBodyParsingMiddleware` — are all you need
for a spec-compliant endpoint. The two validation middleware are opt-in and
intended for dev/CI; they require the optional `opis/json-schema` package (see
[schema validation](schema-validation.md)).

## The per-server model

A `Server` is immutable and itself a PSR-15 `RequestHandlerInterface`. You set its
middleware list with `withMiddleware()` and its inner handler with `withHandler()`,
then call `handle()`:

```php
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $handler = $this->innerHandler();

    foreach (\array_reverse($this->middleware) as $middleware) {
        $handler = new MiddlewareDecorator($middleware, $handler);
    }

    return $handler->handle($request);
}
```

The list is folded **right-to-left**, so the first entry in your array ends up
outermost — it runs first on the way in and last on the way out. Because the fold
is local to the server, two servers can carry different chains (one with the
validation middleware, one without), and nothing global has to know.

## The canonical order

There is one recommended ordering. Outermost first — each middleware wraps every
middleware below it:

1. **`ErrorHandlerMiddleware`** — catches every `JsonApiExceptionInterface`
   (rendered with its own status and errors) and any other `\Throwable` (rendered
   as a `500`), and emits the resulting [`ErrorResponse`](errors-and-exceptions.md) as a PSR-7
   response. A successful response from below passes through unchanged.
2. **`ContentNegotiationMiddleware`** — validates the `Content-Type` / `Accept`
   media-type parameters and negotiates extensions (`415` / `406`), then validates
   query parameters (`400`). See [content negotiation](content-negotiation.md).
3. **`RequestBodyParsingMiddleware`** — forces an early JSON decode and checks the
   top-level member rules, so a malformed or non-conformant body surfaces as a
   `400` here rather than inside the handler. A bodyless request passes through.
4. **`RequestValidationMiddleware`** _(optional, dev/CI)_ — validates the parsed
   request body against the JSON:API JSON Schema; a violation throws
   `RequestBodyInvalidJsonApi` (`400`). Runs after body parsing because it needs
   the parsed body.
5. **Handler** — innermost. The recommended handler is an
   `OperationHandlerInterface` wrapped in `Psr7ToOperationHandlerAdapter`, which
   turns the PSR-7 request into the matching [operation](operations.md) and renders
   the returned [response value object](responses.md). Any `RequestHandlerInterface`
   works.
6. **`ResponseValidationMiddleware`** _(optional, dev/CI)_ — validates the
   **outgoing** rendered document against the JSON:API response JSON Schema. Sits
   just inside the error handler so its check runs last on the unwind.

The order is a **recommendation, not a constraint** — a server can be built with
any middleware list. The error handler being outermost is the only firm rule,
because everything else can throw.

The example app's [`bootstrap`](../examples/music-catalog/src/bootstrap.php) wires
exactly the three-core chain (a router middleware sits between body parsing and the
handler):

```php
$server = $base
    ->withMiddleware([
        new ErrorHandlerMiddleware($base, $debug),
        new ContentNegotiationMiddleware(),
        new RequestBodyParsingMiddleware(),
        new PathPrefixRouter($base),
    ])
    ->withHandler(new MusicCatalogHandler($repository));
```

`ErrorHandlerMiddleware` takes the `Server` so it can reach the PSR-17 factories to
build error responses. Because `Server` is immutable, the error handler is
constructed from the pre-middleware `$base`, and the finished server is the value
you call `handle()` on. The [`PathPrefixRouter`](../examples/music-catalog/src/Http/PathPrefixRouter.php)
is your framework's router in miniature: it attaches the routing
`Operation\Target` and otherwise stays out of the way (the suite is
router-agnostic).

## Why this order

- **Error handler outermost.** It must wrap negotiation, body parsing, and the
  handler so it catches a throwable from *any* of them, not only the handler. If
  it sat lower, a `415` from negotiation or a `400` from body parsing would escape
  unrendered.
- **Negotiation before body parsing.** A media-type mismatch should be rejected
  before any body work — there is no point decoding a body you are about to refuse
  on `Content-Type`.
- **Request validation after body parsing.** It needs the already-parsed body, so
  it sits just inside the parser.
- **Response validation just inside the error handler.** Placed there, its check
  runs **last on the unwind** — it sees the fully rendered PSR-7 response the
  adapter produced — and a thrown `ResponseBodyInvalidJsonApi` still bubbles up to
  the outermost error handler, which renders the `500`.

## The aggregate

`JsonApiMiddleware` composes the three core middleware into a single
`MiddlewareInterface` in the recommended order, for consumers who would rather not
manage ordering:

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
underlying middleware and wires them with the internal `MiddlewareHandler` (which
adapts one middleware plus the next handler into a `RequestHandlerInterface`,
nesting outermost-first). The three building blocks remain independently
constructable; the aggregate only wires them, so you can drop down to the explicit
list the moment you need to slot a router or a validation middleware into the chain.

## How the parsed request flows

The first JSON:API middleware to run wraps the incoming PSR-7 request in a
`Request\JsonApiRequest` and passes **that instance** down the chain:

```php
$jsonApiRequest = $request instanceof JsonApiRequestInterface
    ? $request
    : new JsonApiRequest($request);

return $handler->handle($jsonApiRequest);
```

`JsonApiRequest` *is* a `ServerRequestInterface`, so the wrap is transparent to
generic PSR-15 middleware, and it is **idempotent** — once one middleware has
wrapped the request, the others see a `JsonApiRequestInterface` and leave it alone.
Downstream middleware, the handler, and the operation adapter therefore share one
memoized parse and never re-decode the query params or the body. There is **no
request attribute** for the parsed request; the only routing attribute the suite
reads is `Operation\Target::class`, which your router attaches.

## Optional validation middleware

`RequestValidationMiddleware` and `ResponseValidationMiddleware` add JSON-Schema
validation of the request and response documents. They are **per-server opt-in**:
add them only on the servers where you want them (typically a dev/CI server, not
production). Both take a `Server` and a `DocumentValidator`, and the
`DocumentValidator` requires the optional `opis/json-schema` package — so wiring
fails fast if it is absent.

- `RequestValidationMiddleware` runs **after** body parsing (it needs the parsed
  body) and **before** the handler; a violation throws `RequestBodyInvalidJsonApi`
  (`400`). Bodyless requests pass through.
- `ResponseValidationMiddleware` sits just **inside** the error handler and outside
  negotiation / body parsing, so its check runs last on the unwind, against the
  fully rendered PSR-7 response. By default a violation throws
  `ResponseBodyInvalidJsonApi` (`500`) — a failing response is a server bug, so it
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

See [schema validation](schema-validation.md) for the schema model and the
per-resource schema compiler these middleware compose in.

## What the suite does and does not touch

- **Profiles flow through untouched.** Profiles are advisory: negotiation
  recognises them and the response layer surfaces them, but no middleware mutates
  the request because a profile was asked for. See [profiles](profiles.md).
- **PSR-17 factories come from the `Server`** (`responseFactory()` /
  `streamFactory()`), which `ErrorHandlerMiddleware` reads to build error
  responses. Body parsing builds no responses and needs no factories.
- **Response value objects are not `ResponseInterface`.** A bare PSR-15 handler
  cannot return one; render via the adapter, or call
  `$response->toPsrResponse($server, $request)` yourself.
- **Max body size is not enforced in core.** Cap request size at the web server or
  load balancer — see [security](security.md).

## Next / see also

- [Middleware order is the one canonical treatment above](#the-canonical-order) — there is no separate ordering page.
- [Server](server.md) — the per-server middleware list and how `handle()` runs it.
- [Content negotiation](content-negotiation.md) — what the negotiation middleware checks.
- [Schema validation](schema-validation.md) — the optional JSON-Schema middleware and `DocumentValidator`.
- [Errors](errors-and-exceptions.md) — what the error handler renders.
- [Operations](operations.md) and [responses](responses.md) — what the inner handler consumes and produces.
