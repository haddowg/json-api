# Middleware order

`haddowg/json-api` ships a small PSR-15 middleware suite under
`haddowg\JsonApi\Middleware\` that implements the JSON:API request lifecycle.
Middleware are **per-server**: each `Server` holds its own ordered
middleware list and runs it as a `RequestHandlerInterface`. Server selection is
the routing layer's job — there is no select-server middleware in core, and no
global middleware registry.

## Recommended order

Outermost first (the outermost middleware wraps every middleware below it):

1. **`ErrorHandlerMiddleware`** — outermost. Catches every `JsonApiExceptionInterface`
   (rendered with its own status + errors) and any other `\Throwable` (rendered
   as a 500), and renders the resulting `ErrorResponse` to a PSR-7 response. A
   successful PSR-7 response from below passes through unchanged. It must be
   outermost so it catches exceptions thrown by the negotiation and body-parsing
   middleware as well as by the handler.
2. **`ContentNegotiationMiddleware`** — validates the `Content-Type`/`Accept`
   media-type parameters and negotiates extensions (`415`/`406`), and validates
   query parameters (`400`). Profiles are **advisory** and flow through
   untouched. Runs before body parsing so a content-type mismatch is rejected
   before any body work.
3. **`RequestBodyParsingMiddleware`** — forces an early JSON decode of the
   request body and validates its top-level members (when a body is present) so a
   malformed or non-conformant body surfaces as a `400` here rather than inside
   the handler. A bodyless request passes through.
4. **`RequestValidationMiddleware`** _(optional, dev/CI)_ — validates the parsed
   request body against the JSON:API request JSON Schema (augmented by in-scope
   profile fragments). A violation throws `RequestBodyInvalidJsonApi` (`400`),
   rendered by the outermost error handler. Bodyless requests pass through. Runs
   after body parsing so it receives the already-parsed request. Requires the
   optional `opis/json-schema` package; **per-server opt-in** (add it only where
   you want validation, e.g. a dev/CI server). See
   [`spec-compliance.md`](./spec-compliance.md) for the spec sections it asserts.
5. **Handler** — innermost. The recommended handler is an
   `Operation\OperationHandlerInterface` wrapped in `Operation\Psr7ToOperationHandlerAdapter`,
   which turns the PSR-7 request into the matching `JsonApiOperationInterface`, invokes the
   consumer handler, and renders the returned response value object
   (`DataResponse`, `MetaResponse`, …) to PSR-7. Consumers who prefer PSR-15
   directly can supply any `RequestHandlerInterface`; it returns a PSR-7 response
   that the error handler passes through unchanged. (Response value objects are
   not `ResponseInterface`, so a bare PSR-15 handler cannot return one — render
   via the adapter, or call `$response->toPsrResponse($server, $request)`
   yourself.)
6. **`ResponseValidationMiddleware`** _(optional, dev/CI)_ — validates the
   **outgoing** rendered document against the JSON:API response JSON Schema
   (augmented by in-scope profile fragments) as the response unwinds. A failing
   response is a server bug: by default it throws `ResponseBodyInvalidJsonApi`
   (`500`); a flag downgrades that to logging the violations and passing the
   response through. Only `application/vnd.api+json` responses with a body are
   checked. **Placement:** this middleware is added **just inside the error
   handler** and outside negotiation/body-parsing — that way its check runs last
   on the unwind (it sees the fully rendered PSR-7 response the adapter produced)
   and a thrown `ResponseBodyInvalidJsonApi` bubbles up to the outermost error
   handler, which renders the `500`. Requires `opis/json-schema`; **per-server
   opt-in**.

The order is a **recommendation, not a constraint** — a server can be built with
any middleware list. The error handler being outermost is the only firm
recommendation. The two validation middleware (4, 6) are optional and intended
for dev/CI: enable them per-server (e.g. on a staging/v2 server but not in
production), and only the package's `opis/json-schema` suggestion needs
installing where they run.

## How the parsed request flows

The first JSON:API middleware to run wraps the incoming PSR-7 request in a
`Request\JsonApiRequest` (which *is* a `ServerRequestInterface`) and passes that
instance down the chain. The wrap is idempotent, so downstream middleware, the
handler, and the operation adapter receive the already-parsed, memoized request
and never re-parse. There is no request **attribute** for the parsed request; the
only routing attribute the suite reads is `Operation\Target::class`.

## Aggregate

`Middleware\JsonApiMiddleware` composes the three middleware in the order above
behind a single `MiddlewareInterface`, for consumers who do not want to manage
ordering. The three building blocks remain independently constructable.

## Notes

- **PSR-17 factories** come from the `Server` (`responseFactory()` /
  `streamFactory()`), which `ErrorHandlerMiddleware` reads to build error
  responses. Body parsing builds no responses and needs no factories.
- **Max body size** is not enforced in core; cap request size at the web
  server / load balancer.

See [`spec-compliance.md`](./spec-compliance.md) for spec-coverage detail.
