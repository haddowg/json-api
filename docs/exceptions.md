# Exceptions

`haddowg/json-api` uses a **typed exception hierarchy** to signal everything that
can go wrong in the request lifecycle. Each exception carries its own JSON:API
error data and HTTP status, so throwing one is enough to produce a spec-compliant
error document — the [error handler](errors.md) catches it and renders it. There
is no factory to inject and no indirection: you throw the exception directly and
the library catches it. This page documents the contract and lists every concrete
exception.

## The contract

Every exception implements `Exception\JsonApiExceptionInterface`, which extends
`\Throwable` and adds two methods:

```php
interface JsonApiExceptionInterface extends \Throwable
{
    /** @return list<Error> the JSON:API error objects describing what went wrong */
    public function getErrors(): array;

    /** The HTTP status code the response should carry. */
    public function getStatusCode(): int;
}
```

The exception exposes the error **data** (`Schema\Error\Error` value objects) and
the status — it never builds a document. Assembling the document is the
serialization layer's job. This is what lets the [error handler](errors.md) turn
any thrown `JsonApiExceptionInterface` into an `ErrorResponse` and render it.

`Exception\AbstractJsonApiException` is the base for the concrete classes. It
extends `\Exception`, takes `(string $message, int $statusCode)`, forwards both to
`parent::__construct()` (so `getCode()` mirrors the status), and implements
`getStatusCode()`; each subclass implements `getErrors()`. You only interact with
this base if you write your own exception (see [below](#writing-your-own)).

## Throwing one

Throw a typed exception from anywhere downstream of the error handler — a
middleware, the adapter, your handler, a serializer, a hydrator:

```php
use haddowg\JsonApi\Exception\ResourceNotFound;

if ($article === null) {
    throw new ResourceNotFound(); // → 404 error document
}
```

The library's own request parsing, content negotiation, and hydration throw these
same exceptions, so malformed requests render as error documents with no code on
your part. See [Errors](errors.md) for the full propagation story.

## The concrete exceptions

Each lives under the `haddowg\JsonApi\Exception` namespace. The **status** is the
HTTP status the response carries; the **code** is the JSON:API error object's
`code` member.

### Request body & document structure

| Exception | Status | Error code | Thrown when |
|---|---|---|---|
| `DataMemberMissing` | 400 | `DATA_MEMBER_MISSING` | The top-level `data` member is absent where required. |
| `ResourceTypeMissing` | 400 | `RESOURCE_TYPE_MISSING` | A resource object has no `type`. |
| `ResourceIdMissing` | 400 | `RESOURCE_ID_MISSING` | A resource object that must carry an `id` has none. |
| `ResourceIdInvalid` | 400 | `RESOURCE_ID_INVALID` | The `id` member is present but not a string. |
| `RequestBodyInvalidJson` | 400 | `REQUEST_BODY_INVALID_JSON` | The request body is not well-formed JSON. |
| `RequestBodyInvalidJsonApi` | 400 | `REQUEST_BODY_INVALID_JSON_API` | The request body fails JSON:API [schema validation](validation.md). |
| `RequiredTopLevelMembersMissing` | 400 | `REQUIRED_TOP_LEVEL_MEMBERS_MISSING` | The document has none of `data`, `errors`, `meta`. |
| `TopLevelMemberNotAllowed` | 400 | `TOP_LEVEL_MEMBER_NOT_ALLOWED` | `included` is present without a top-level `data`. |
| `TopLevelMembersIncompatible` | 400 | `TOP_LEVEL_MEMBERS_INCOMPATIBLE` | `data` and `errors` coexist in one document. |

### Resource identifiers

| Exception | Status | Error code | Thrown when |
|---|---|---|---|
| `ResourceIdentifierTypeMissing` | 400 | `RESOURCE_IDENTIFIER_TYPE_MISSING` | A resource identifier has no `type`. |
| `ResourceIdentifierTypeInvalid` | 400 | `RESOURCE_IDENTIFIER_TYPE_INVALID` | A resource identifier's `type` is not a string. |
| `ResourceIdentifierIdMissing` | 400 | `RESOURCE_IDENTIFIER_ID_MISSING` | A resource identifier carries neither `id` nor `lid`. |
| `ResourceIdentifierIdInvalid` | 400 | `RESOURCE_IDENTIFIER_ID_INVALID` | A resource identifier's `id` is not a string. |
| `ResourceIdentifierLidInvalid` | 400 | `RESOURCE_IDENTIFIER_LID_INVALID` | A resource identifier's `lid` is not a string. |

### Client-generated ids

| Exception | Status | Error code | Thrown when |
|---|---|---|---|
| `ClientGeneratedIdNotSupported` | 403 | `CLIENT_GENERATED_ID_NOT_SUPPORTED` | The client supplied an `id` but the type does not accept client-generated ids. |
| `ClientGeneratedIdRequired` | 403 | `CLIENT_GENERATED_ID_REQUIRED` | The type requires a client-generated `id` and none was supplied. |
| `ClientGeneratedIdAlreadyExists` | 409 | `CLIENT_GENERATED_ID_ALREADY_EXISTS` | The supplied client-generated `id` is already in use. |

### Relationships

| Exception | Status | Error code | Thrown when |
|---|---|---|---|
| `RelationshipNotExists` | 404 | `RELATIONSHIP_NOT_EXISTS` | The requested relationship does not exist on the resource. |
| `RelationshipTypeInappropriate` | 400 | `RELATIONSHIP_TYPE_INAPPROPRIATE` | A to-one/to-many relationship's data has the wrong cardinality for the target. |
| `FullReplacementProhibited` | 403 | `FULL_REPLACEMENT_PROHIBITED` | A `PATCH` attempts full replacement of a relationship that forbids it. |
| `RemovalProhibited` | 403 | `REMOVAL_PROHIBITED` | A removal of a relationship that forbids it is attempted. |
| `ResourceTypeUnacceptable` | 409 | `RESOURCE_TYPE_UNACCEPTABLE` | A resource `type` is not a string or is rejected by the hydrator. |

### Query parameters

| Exception | Status | Error code | Thrown when |
|---|---|---|---|
| `QueryParamMalformed` | 400 | `QUERY_PARAM_MALFORMED` | A query parameter's value is malformed. |
| `QueryParamUnrecognized` | 400 | `QUERY_PARAM_UNRECOGNIZED` | A query parameter is not recognized. |
| `InclusionUnsupported` | 400 | `INCLUSION_UNSUPPORTED` | The endpoint does not support `include`. |
| `InclusionUnrecognized` | 400 | `INCLUSION_UNRECOGNIZED` | An `include` path is not recognized for the type. |
| `SortingUnsupported` | 400 | `SORTING_UNSUPPORTED` | The endpoint does not support `sort`. |
| `SortParamUnrecognized` | 400 | `SORTING_UNRECOGNIZED` | A `sort` field is not recognized. |

### Content negotiation

| Exception | Status | Error code | Thrown when |
|---|---|---|---|
| `MediaTypeUnsupported` | 415 | `MEDIA_TYPE_UNSUPPORTED` | The request `Content-Type` media type is not supported. |
| `MediaTypeUnacceptable` | 406 | `MEDIA_TYPE_UNACCEPTABLE` | No `Accept` media type can be satisfied. |

### Server-side / lifecycle

| Exception | Status | Error code | Thrown when |
|---|---|---|---|
| `ResourceNotFound` | 404 | `RESOURCE_NOT_FOUND` | The requested resource does not exist. |
| `NoResourceRegistered` | 500 | `NO_RESOURCE_REGISTERED` | A serializer/hydrator is requested for an unregistered type — a wiring fault. |
| `ApplicationError` | 500 | `APPLICATION_ERROR` | A generic application error (e.g. routing failed to attach a `Target`). |
| `ResponseBodyInvalidJson` | 500 | `RESPONSE_BODY_INVALID_JSON` | A rendered response body is not well-formed JSON. |
| `ResponseBodyInvalidJsonApi` | 500 | `RESPONSE_BODY_INVALID_JSON_API` | A rendered response fails JSON:API [schema validation](validation.md). |

> The `5xx` exceptions signal **server configuration or internal faults**, not
> client mistakes. `NoResourceRegistered`, for instance, means a type was used
> without being registered on the [server](server.md). They still render as proper
> error documents so the client receives valid JSON:API.

## Writing your own

Domain-specific failures fit the same model. Extend `AbstractJsonApiException`,
pass your message and status up, and return your error data:

```php
use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

final class PaymentRequired extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Payment is required to access this resource.', 402);
    }

    public function getErrors(): array
    {
        return [new Error(
            status: '402',
            code: 'PAYMENT_REQUIRED',
            title: 'Payment required',
            detail: $this->getMessage(),
        )];
    }
}
```

Throwing it works exactly like the built-in exceptions: the [error
handler](errors.md) catches it and renders a `402` error document. Reference global
classes like `\Exception` with the leading backslash inline, matching the codebase
style.

## Related pages

- [Errors](errors.md) — how exceptions propagate and the generic 500.
- [Middleware](middleware.md) — the error handler that catches them.
- [Responses](responses.md) — `ErrorResponse`, the rendered output.
- [Concepts](concepts.md#errors) — the `Error` value object.
- [Documentation index](README.md) — the full page list.
