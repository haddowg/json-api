# Middleware order

> **Status: stub.** This page records the PSR-15 middleware suite introduced in
> Phase 3 and the recommended order so the documentation phase (Phase 5) has an
> anchor. It is not yet full consumer documentation.

`haddowg/json-api` ships a small PSR-15 middleware suite under
`haddowg\JsonApi\Middleware\` that implements the JSON:API request lifecycle.
Middleware are **per-server**: each `Server` (Phase 4.5) holds its own ordered
middleware list and runs it as a `RequestHandlerInterface`. Server selection is
the routing layer's job — there is no select-server middleware in core, and no
global middleware registry.

## Recommended order

Outermost first (the outermost middleware wraps every middleware below it):

1. **`ErrorHandlerMiddleware`** — outermost. Catches every `JsonApiException`
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
   request body (when a body is present) so malformed JSON surfaces as a `400`
   here rather than inside the handler. A bodyless request passes through.
4. _(**Atomic-operations dispatch** — reserved slot, post-1.0 candidate. When
   implemented, this middleware reads the `atomic:operations` array from the
   parsed body, constructs one `JsonApiOperation` per member, dispatches each
   through the inner `OperationHandler`, and aggregates the results into an
   `atomic:results` document. It sits **after** body parsing because it needs the
   parsed body to enumerate operations, and **before** the handler because it
   controls dispatch. No code ships for this slot in 1.0.)_
5. **Handler** — innermost. The recommended handler is an
   `Operation\OperationHandler` wrapped in `Operation\Psr7ToOperationHandlerAdapter`,
   which turns the PSR-7 request into the matching `JsonApiOperation`, invokes the
   consumer handler, and renders the returned response value object
   (`DataResponse`, `MetaResponse`, …) to PSR-7. Consumers who prefer PSR-15
   directly can supply any `RequestHandlerInterface`; it returns a PSR-7 response
   that the error handler passes through unchanged. (Response value objects are
   not `ResponseInterface`, so a bare PSR-15 handler cannot return one — render
   via the adapter, or call `$response->toPsrResponse($server, $request)`
   yourself.)

The order is a **recommendation, not a constraint** — a server can be built with
any middleware list. The error handler being outermost is the only firm
recommendation.

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
