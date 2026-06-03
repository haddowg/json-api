# Errors

When a request goes wrong, JSON:API responds with a document carrying `errors`
instead of `data`. In `haddowg/json-api` the normal way to produce one is to
**throw a typed exception** anywhere in the request lifecycle: the outermost
[`ErrorHandlerMiddleware`](middleware.md) catches it, turns it into an
[`ErrorResponse`](responses.md), and renders a spec-compliant error document. You
almost never assemble an error document by hand. This page covers how errors
propagate and render; for the full list of typed exceptions see
[Exceptions](exceptions.md).

## The flow

```
throw SomeJsonApiException
   → ErrorHandlerMiddleware catches it (outermost in the chain)
      → ErrorResponse::fromException($exception)
         → rendered to a JSON:API error document (HTTP status from the exception)
```

Every typed exception implements [`Exception\JsonApiExceptionInterface`](exceptions.md),
which exposes the error *data* (`getErrors(): list<Error>`) and the HTTP status
(`getStatusCode(): int`). `ErrorResponse::fromException()` reads `getErrors()`, and
the rendered document derives its status from the errors. So throwing is enough —
the exception carries everything the document needs.

```php
use haddowg\JsonApi\Exception\ResourceNotFound;

$article = $this->repository->find($id);
if ($article === null) {
    throw new ResourceNotFound(); // becomes a 404 error document
}
```

Because the error handler is the outermost middleware, this works from anywhere
downstream: a middleware, the adapter, your handler, a serializer, a hydrator. The
library's own request parsing and validation throw these same typed exceptions, so
malformed requests render as error documents without any code on your part.

## Returning errors from a handler

A handler may also return an `ErrorResponse` directly instead of throwing — both
reach the same renderer. `fromException()` wraps a typed exception; `fromErrors()`
builds the document from `Schema\Error\Error` value objects you construct yourself:

```php
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

return ErrorResponse::fromException(new ResourceNotFound());

return ErrorResponse::fromErrors(
    new Error(
        status: '422',
        code: 'TITLE_TAKEN',
        title: 'Validation failed',
        detail: 'An article with this title already exists.',
        source: ErrorSource::fromPointer('/data/attributes/title'),
    ),
);
```

A returned `ErrorResponse` is rendered by the operations adapter just like any
other response value object. A thrown exception is rendered by the error handler.
Use whichever reads better at the call site — throwing is usually cleaner for
"stop here, this is wrong", returning for "this branch produces an error".

See [Responses](responses.md#errorresponse) for the `ErrorResponse` surface and
[Concepts](concepts.md#errors) for the `Error` / `ErrorSource` value objects.

## Unexpected throwables: the generic 500

The error handler catches *any* `\Throwable`, not just `JsonApiExceptionInterface`. A
non-JSON:API throwable (a bug, a database failure, a third-party error) is rendered
as a generic `500 Internal Server Error` document so the client always receives a
valid JSON:API response.

What that 500 contains depends on the `$debug` flag passed to
`ErrorHandlerMiddleware` (and to the [`JsonApiMiddleware`](middleware.md)
aggregate):

- **`$debug = false` (default, production)** — a bare error: `status: "500"`,
  `title: "Internal Server Error"`, nothing else. The original message, file, and
  trace never leak.
- **`$debug = true` (development)** — the throwable's message becomes the error
  `detail`, its code becomes the error `code`, and the diagnostic details go into
  the **error object's** `meta`:

  ```json
  {
      "errors": [
          {
              "status": "500",
              "title": "Internal Server Error",
              "detail": "Undefined method ...",
              "meta": {
                  "exception": "RuntimeException",
                  "file": "/app/src/...",
                  "line": 42,
                  "trace": [ ... ]
              }
          }
      ]
  }
  ```

The diagnostics live in `meta` because that is the spec-faithful home: an error
object's `source` locates request parts, and there is no standard member for a
stack trace. This mirrors `laravel-json-api/exceptions`. If you pass a PSR-3
`LoggerInterface` to the error handler, every unexpected throwable is also logged
at `error` level regardless of the debug flag.

The mapping itself is a public, stateless seam,
`Schema\Error\InternalServerError::for(\Throwable $throwable, bool $debug = false): Error`,
so a framework integration that owns its own error handling can produce the exact
same generic-500 error object without re-implementing it. The seam is pure: it
returns the single `Error` value object and does **not** log, derive an HTTP
status, or build a response — the caller wraps it (`ErrorResponse::fromErrors(...)`)
and logs as it sees fit.

> Leave `$debug` off in production. The trace and message can disclose internals.

## What the error handler does *not* do

The error handler does not inspect a successful return value — a PSR-15 handler can
only return a PSR-7 response, and the [response value objects](responses.md) are
not `ResponseInterface`, so consumer value objects are rendered by the operations
adapter, not here. A successful PSR-7 response passes through the error handler
unchanged.

## Related pages

- [Exceptions](exceptions.md) — every typed exception, its status, and error code.
- [Responses](responses.md) — `ErrorResponse` and the other response value objects.
- [Middleware](middleware.md) — the error handler's place in the chain.
- [Concepts](concepts.md#errors) — the `Error` and `ErrorSource` value objects.
- [Documentation index](README.md) — the full page list.
