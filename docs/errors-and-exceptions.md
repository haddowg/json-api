# Errors and the exception catalogue

When a request goes wrong, JSON:API responds with a document carrying `errors`
instead of `data`. In `haddowg/json-api` you produce one the same way you signal
any failure in PHP: you **throw a typed exception** anywhere downstream. The
outermost [`ErrorHandlerMiddleware`](middleware.md) catches it, turns it into an
[`ErrorResponse`](responses.md), and renders a spec-compliant error document whose
HTTP status is the one the exception declares. You almost never assemble an error
document by hand. This page covers how errors propagate, the alternative
return-an-error path, the generic 500 for unexpected failures, and the full typed
exception catalogue.

## The model: throw a typed exception

Every failure the library knows about is a typed exception carrying its own
JSON:API error data and HTTP status. Throwing one is enough — the
[error handler](middleware.md) reads the data off it and renders the document:

```
throw SomeJsonApiException
   → ErrorHandlerMiddleware catches it (outermost in the chain)
      → ErrorResponse::fromException($exception)
         → a JSON:API error document (HTTP status from the exception)
```

Because the error handler wraps the whole chain, this works from anywhere
downstream — a middleware, your data adapter, your handler, a serializer, a
hydrator. The library's own request parsing, content negotiation, and hydration
throw these same exceptions, so a malformed request renders as a proper error
document with no code on your part. In the worked
[`MusicCatalogHandler`](../examples/music-catalog/src/Handler/MusicCatalogHandler.php)
a missing resource is just a throw:

```php
use haddowg\JsonApi\Exception\ResourceNotFound;

$model = $this->repository->fetchOne($type, $id);
if ($model === null) {
    return ErrorResponse::fromException(new ResourceNotFound()); // → 404 error document
}
```

Every typed exception implements
[`Exception\JsonApiExceptionInterface`](#the-contract), which exposes the error
*data* (`getErrors(): list<Error>`) and the HTTP status (`getStatusCode(): int`).
`ErrorResponse::fromException()` reads both, so throwing carries everything the
document needs.

## The alternative: return an error from a handler

A handler may **return** an `ErrorResponse` instead of throwing — both reach the
same renderer. `fromException()` wraps a typed exception; `fromErrors()` builds the
document from `Schema\Error\Error` value objects you construct by hand:

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
        detail: 'A playlist with this name already exists.',
        source: ErrorSource::fromPointer('/data/attributes/name'),
    ),
);
```

A returned `ErrorResponse` is rendered by the operations adapter like any other
[response value object](responses.md); a thrown exception is rendered by the error
handler. Use whichever reads better at the call site — **throw** for "stop here,
this is wrong" (it short-circuits the call stack), **return** for "this branch
produces an error" alongside the success branches. The `MusicCatalogHandler` does
both: it `return`s `ErrorResponse::fromException(new ResourceNotFound())` from its
read/write arms (the error sits beside the `DataResponse` returns), and it `throw`s
the relationship-mutation `403`s deep inside a helper where unwinding the stack is
the point.

When `fromErrors()` carries errors that share one HTTP status, that status is used;
a mix is rounded to the nearest applicable class (e.g. a `422` and a `400` round to
`400`). `fromException()` always uses the status the exception declares.

## Building an `Error` by hand

[`Schema\Error\Error`](concepts.md#errors) is a construct-only, immutable value
object. Every member is optional per the spec; an absent string member is the
empty string and an absent structured member is `null`, and each is omitted from
the rendered document. Use named arguments:

| Member | Type | Purpose |
|---|---|---|
| `id` | `string` | A unique identifier for this occurrence of the problem. |
| `status` | `string` | The HTTP status as a string (`'422'`). |
| `code` | `string` | An application-specific error code. |
| `title` | `string` | A short, human-readable summary that does not vary per occurrence. |
| `detail` | `string` | A human-readable explanation specific to this occurrence. |
| `source` | `?ErrorSource` | Locates the cause in the request (see below). |
| `links` | `?ErrorLinks` | An `about`/`type` link for the error. |
| `meta` | `array<string, mixed>` | Non-standard meta-information. |

The `source` member points at the part of the request that caused the error. It
has three mutually exclusive constructors — pick the one matching where the cause
lives:

| Constructor | Member emitted | Locates the cause in |
|---|---|---|
| `ErrorSource::fromPointer('/data/attributes/name')` | `pointer` | the request **document body** (a JSON Pointer, RFC 6901) |
| `ErrorSource::fromParameter('filter[genre]')` | `parameter` | a **query parameter** |
| `ErrorSource::fromHeader('Accept')` | `header` | a request **header** |

## Unexpected throwables: the generic 500

The error handler catches *any* `\Throwable`, not just `JsonApiExceptionInterface`.
A non-JSON:API throwable (a bug, a database failure, a third-party error) becomes a
generic `500 Internal Server Error` document, so the client always receives valid
JSON:API. What that 500 contains depends on the `$debug` flag passed to
[`ErrorHandlerMiddleware`](middleware.md):

- **`$debug = false` (default, production)** — a redacted error: `status: "500"`,
  `title: "Internal Server Error"`, nothing else. The original message, file, and
  trace never leak.
- **`$debug = true` (development)** — the throwable's message becomes the error
  `detail`, its non-zero code becomes the error `code`, and the diagnostics go into
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
object's `source` locates request parts and there is no standard member for a
stack trace. The trace has its call **arguments stripped** from every frame (so no
request value or secret rides along in the trace), mirroring
`laravel-json-api/exceptions`.

The mapping itself is a public, stateless seam,
`Schema\Error\InternalServerError::for(\Throwable $throwable, bool $debug = false): Error`,
so a framework integration that owns its own error handling produces the exact same
generic-500 error object without re-implementing it. The seam is pure: it returns
the single `Error` value object and does **not** log, derive an HTTP status, or
build a response — the caller wraps it (`ErrorResponse::fromErrors(...)`) and logs
as it sees fit.

If you pass a PSR-3 `LoggerInterface` to the error handler, every unexpected
throwable is also logged at `error` level — **regardless of the debug flag** — with
the throwable under the `exception` log context. Typed `JsonApiExceptionInterface`
throwables are *not* logged: they are expected, client-facing outcomes.

> Leave `$debug` off in production. The trace, message, and file path can disclose
> internals.

## The contract

Every typed exception implements `Exception\JsonApiExceptionInterface`, which
extends `\Throwable` and adds two methods:

```php
interface JsonApiExceptionInterface extends \Throwable
{
    /** @return list<Error> the JSON:API error objects describing what went wrong */
    public function getErrors(): array;

    /** The HTTP status code the response should carry. */
    public function getStatusCode(): int;
}
```

The exception exposes the error **data** and the status — it never builds a
document; assembling the document is the serialization layer's job. There is no
factory to inject and no indirection: you throw the exception directly and the
library catches it.

`Exception\AbstractJsonApiException` is the base for every concrete class. It
extends `\Exception`, takes `(string $message, int $statusCode)`, forwards both to
`parent::__construct()` (so `getCode()` mirrors the status), and implements
`getStatusCode()`; each subclass implements `getErrors()`. You only touch this base
when you write your own exception.

## Writing your own exception

Domain-specific failures fit the same model. Extend `AbstractJsonApiException`,
pass your message and status up, and return your error data. The example app's
[`PaymentRequired`](../examples/music-catalog/src/Exception/PaymentRequired.php) is
the worked witness — an HTTP `402` the catalogue itself never defines:

```php
use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

final class PaymentRequired extends AbstractJsonApiException
{
    public function __construct(string $detail = 'This operation requires an active premium subscription.')
    {
        parent::__construct($detail, 402);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '402',
                code: 'PAYMENT_REQUIRED',
                title: 'Payment required',
                detail: $this->getMessage(),
            ),
        ];
    }
}
```

The handler `throw`s it when a write demands a capability the caller lacks —
creating a private playlist without the `premium` flag, in `guardPremium()`. It
then flows through the *same* [`ErrorHandlerMiddleware`](middleware.md) as the
built-in catalogue and renders a spec-compliant `402` with no special-casing.
Reference global classes like `\Exception` with a leading backslash inline, matching
the codebase style.

## The exception catalogue

Every concrete exception lives under the `haddowg\JsonApi\Exception` namespace. The
**status** is the HTTP status the response carries; the **code** is the JSON:API
error object's `code` member; the **source** column names the error object's
`source` kind (a `pointer` into the body, a query `parameter`, or none).

### Request body & document structure

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `DataMemberMissing` | 400 | `DATA_MEMBER_MISSING` | pointer | The top-level `data` member is absent where required. |
| `ResourceTypeMissing` | 400 | `RESOURCE_TYPE_MISSING` | pointer | A resource object has no `type`. |
| `ResourceIdMissing` | 400 | `RESOURCE_ID_MISSING` | pointer | A resource object that must carry an `id` has none. |
| `ResourceIdInvalid` | 400 | `RESOURCE_ID_INVALID` | pointer | The `id` member is present but not a string. |
| `RequestBodyInvalidJson` | 400 | `REQUEST_BODY_INVALID_JSON` | — | The request body is not well-formed JSON. |
| `RequestBodyInvalidJsonApi` | 400 | `REQUEST_BODY_INVALID_JSON_API` | pointer | The body fails JSON:API [schema validation](schema-validation.md). |
| `RequiredTopLevelMembersMissing` | 400 | `REQUIRED_TOP_LEVEL_MEMBERS_MISSING` | — | The document has none of `data`, `errors`, `meta`. |
| `TopLevelMemberNotAllowed` | 400 | `TOP_LEVEL_MEMBER_NOT_ALLOWED` | — | `included` is present without a top-level `data`. |
| `TopLevelMembersIncompatible` | 400 | `TOP_LEVEL_MEMBERS_INCOMPATIBLE` | — | `data` and `errors` coexist in one document. |

### Resource identifiers

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `ResourceIdentifierTypeMissing` | 400 | `RESOURCE_IDENTIFIER_TYPE_MISSING` | — | A resource identifier has no `type`. |
| `ResourceIdentifierTypeInvalid` | 400 | `RESOURCE_IDENTIFIER_TYPE_INVALID` | — | A resource identifier's `type` is not a string. |
| `ResourceIdentifierIdMissing` | 400 | `RESOURCE_IDENTIFIER_ID_MISSING` | — | A resource identifier carries neither `id` nor `lid`. |
| `ResourceIdentifierIdInvalid` | 400 | `RESOURCE_IDENTIFIER_ID_INVALID` | — | A resource identifier's `id` is not a string. |
| `ResourceIdentifierLidInvalid` | 400 | `RESOURCE_IDENTIFIER_LID_INVALID` | — | A resource identifier's `lid` is not a string. |

### Client-generated ids

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `ClientGeneratedIdNotSupported` | 403 | `CLIENT_GENERATED_ID_NOT_SUPPORTED` | pointer | The client supplied an `id` but the type does not accept client-generated ids. |
| `ClientGeneratedIdRequired` | 403 | `CLIENT_GENERATED_ID_REQUIRED` | pointer | The type requires a client-generated `id` and none was supplied. |
| `ClientGeneratedIdAlreadyExists` | 409 | `CLIENT_GENERATED_ID_ALREADY_EXISTS` | pointer | The supplied client-generated `id` is already in use. |

### Relationships

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `RelationshipNotExists` | 404 | `RELATIONSHIP_NOT_EXISTS` | — | The requested relationship does not exist on the resource (or its endpoint is suppressed). |
| `RelationshipTypeInappropriate` | 400 | `RELATIONSHIP_TYPE_INAPPROPRIATE` | pointer | A relationship's linkage has the wrong cardinality (e.g. a `POST`/`DELETE` against a to-one). |
| `FullReplacementProhibited` | 403 | `FULL_REPLACEMENT_PROHIBITED` | pointer | A full replacement of a relationship that forbids it is attempted. |
| `AdditionProhibited` | 403 | `ADDITION_PROHIBITED` | pointer | An addition to a relationship that forbids it is attempted. |
| `RemovalProhibited` | 403 | `REMOVAL_PROHIBITED` | pointer | A removal from a relationship that forbids it is attempted. |
| `ResourceTypeUnacceptable` | 409 | `RESOURCE_TYPE_UNACCEPTABLE` | pointer | A resource `type` is not a string or is rejected by the hydrator. |

### Query parameters

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `QueryParamMalformed` | 400 | `QUERY_PARAM_MALFORMED` | parameter | A query parameter's value is malformed. |
| `QueryParamUnrecognized` | 400 | `QUERY_PARAM_UNRECOGNIZED` | parameter | A query parameter is not recognized. |
| `FilterParamUnrecognized` | 400 | `FILTERING_UNRECOGNIZED` | parameter | A `filter[...]` key is not recognized for the type. |
| `InclusionUnsupported` | 400 | `INCLUSION_UNSUPPORTED` | parameter | The endpoint does not support `include`. |
| `InclusionUnrecognized` | 400 | `INCLUSION_UNRECOGNIZED` | parameter | An `include` path is not recognized for the type. |
| `SortingUnsupported` | 400 | `SORTING_UNSUPPORTED` | parameter | The endpoint does not support `sort`. |
| `SortParamUnrecognized` | 400 | `SORTING_UNRECOGNIZED` | parameter | A `sort` field is not recognized. |

> Two codes deliberately differ from their class name:
> `FilterParamUnrecognized` carries `FILTERING_UNRECOGNIZED` and
> `SortParamUnrecognized` carries `SORTING_UNRECOGNIZED`. Match the **code** string
> exactly when you assert on it — do not derive it from the class name.

### Content negotiation

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `MediaTypeUnsupported` | 415 | `MEDIA_TYPE_UNSUPPORTED` | parameter | The request `Content-Type` media type is not supported. |
| `MediaTypeUnacceptable` | 406 | `MEDIA_TYPE_UNACCEPTABLE` | parameter | No `Accept` media type can be satisfied. |

The `415` / `406` asymmetry is intentional — see
[content negotiation](content-negotiation.md) for why an unsupported
`Content-Type` and an unsatisfiable `Accept` get different statuses.

### Resource & lifecycle

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `ResourceNotFound` | 404 | `RESOURCE_NOT_FOUND` | — | The requested resource does not exist. |
| `NoResourceRegistered` | 500 | `NO_RESOURCE_REGISTERED` | — | A serializer/hydrator is requested for an unregistered type — a wiring fault. |
| `ApplicationError` | 500 | `APPLICATION_ERROR` | — | A generic application error (e.g. routing failed to attach a `Target`). |

### Response side (defensive, dev/CI)

| Exception | Status | Error code | Source | Thrown when |
|---|---|---|---|---|
| `ResponseBodyInvalidJson` | 500 | `RESPONSE_BODY_INVALID_JSON` | — | A rendered response body is not well-formed JSON. |
| `ResponseBodyInvalidJsonApi` | 500 | `RESPONSE_BODY_INVALID_JSON_API` | pointer | A rendered response fails JSON:API [schema validation](schema-validation.md). |

The two response-side exceptions surface only when the optional
[`ResponseValidationMiddleware`](middleware.md) is wired — a development/CI guard
that validates your *own* output against the spec.

> **5xx signals a server fault, not a client mistake.** `NoResourceRegistered`,
> `ApplicationError`, and the two response-side exceptions mean a configuration or
> internal problem — a type used without being registered on the
> [server](server.md), routing that did not attach a `Target`, output your code
> produced that does not validate. They still render as proper error documents so
> the client receives valid JSON:API, but they are *your* bug to fix, never the
> client's.

## What the error handler does *not* do

The error handler does **not** inspect a successful return value. A PSR-15 handler
can only return a PSR-7 response, and the [response value objects](responses.md) are
not `ResponseInterface`, so a returned `DataResponse`/`ErrorResponse`/etc. is
rendered by the operations adapter, not here. A successful PSR-7 response passes
through the error handler unchanged. The handler's one job is to catch what is
*thrown*.

## Next / See also

- [Middleware](middleware.md) — where `ErrorHandlerMiddleware` sits and why it must
  be outermost.
- [Responses](responses.md) — `ErrorResponse` and the success-side response value
  objects.
- [Concepts](concepts.md#errors) — the `Error` and `ErrorSource` value objects in
  the wider model.
- [Content negotiation](content-negotiation.md) — the `415`/`406` asymmetry in
  full.
- [Documentation index](index.md) — the full page list (and the pre-1.0 / install
  caveats).
