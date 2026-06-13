# Content negotiation and request validation

JSON:API pins a single media type — `application/vnd.api+json` — and constrains
which parameters it may carry and which query parameters and body members it will
accept. This page describes the rules `haddowg/json-api` enforces on a request
**before your handler runs**, so you can predict exactly when a request earns a
`415`, a `406`, or a `400` rather than reaching your code.

You rarely call any of this yourself: the shipped [middleware](middleware.md)
compose it for you, and the [response layer](responses.md) emits the matching
media type on the way out. But the rule engine is a small, public surface, and
knowing it makes the failure modes obvious.

## The single validator surface

`Negotiation\RequestValidator` is the public entry point for everything described
here. It is a thin, stateless object whose only state is the server's
supported-extension set, and its four methods map one-to-one onto the four checks:

| Method | Checks | On failure |
| --- | --- | --- |
| `negotiate()` | `Content-Type` / `Accept` media-type parameters + extension support | `415` / `406` |
| `validateQueryParams()` | the JSON:API query-parameter families | `400` `QueryParamUnrecognized` |
| `validateJsonBody()` | the request body is well-formed JSON | `400` `RequestBodyInvalidJson` |
| `validateTopLevelMembers()` | the body's top-level `data` / `errors` / `meta` structure | `400` |

The shipped middleware split these across two stages:
[`ContentNegotiationMiddleware`](middleware.md) runs `negotiate()` +
`validateQueryParams()` on every request, and `RequestBodyParsingMiddleware` runs
`validateJsonBody()` + `validateTopLevelMembers()` once a body is present. A
framework integration can reuse the same methods in its own middleware. The
underlying `@internal Request\MediaType` is the rule engine for the media-type
checks; you never touch it directly.

Every check throws a typed exception (see [errors and exceptions](errors-and-exceptions.md));
the outermost [`ErrorHandlerMiddleware`](middleware.md) renders it as a
spec-compliant error document with the status shown above.

## The media type and its parameters

Per JSON:API 1.1, the media type `application/vnd.api+json` **MUST NOT** carry any
media-type parameter other than `ext` or `profile`. `negotiate()` applies that
rule, but the **`Content-Type` and `Accept` rules differ** — an asymmetry the spec
deliberately requires:

- **`Content-Type`** — the single media type must carry only `ext` / `profile`
  parameters. Any other parameter (a stray `charset`, say) is rejected with
  **415 Unsupported Media Type** (`MediaTypeUnsupported`).
- **`Accept`** — rejected with **406 Not Acceptable** (`MediaTypeUnacceptable`)
  **only when every** `application/vnd.api+json` instance in the header carries a
  forbidden parameter. A single conforming instance makes the whole header
  acceptable.

The asymmetry, worked. This `Content-Type` is rejected (`415`) because its one
JSON:API instance carries a forbidden `charset`:

```
Content-Type: application/vnd.api+json; charset=utf-8
```

This `Accept` is **accepted** — the second instance carries no forbidden
parameter, so the header has a conforming way to be satisfied:

```
Accept: application/vnd.api+json; charset=utf-8, application/vnd.api+json
```

The optional `q` weight is not a media-type parameter and is ignored on `Accept`.
A header that does not assert the JSON:API media type at all (absent, `*/*`, a
different subtype), or asserts it with no parameters, is acceptable — negotiation
only polices the JSON:API media type's own parameters. The parser is quote-aware,
so a comma inside a quoted `profile` / `ext` value never splits one media-type
instance into two.

In practice your requests just assert the bare media type and pass, as the example
suite does on every read and write — see the request helpers in
[`WritesTest`](../examples/music-catalog/tests/WritesTest.php):

```php
$this->server()->handle(new \Nyholm\Psr7\ServerRequest(
    $method,
    'https://music.example' . $path,
    [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
    ],
    (string) \json_encode($body),
));
```

The response carries the same media type back, set automatically by the response
layer:

```php
self::assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
```

## Query parameters

`validateQueryParams()` rejects an unrecognized JSON:API-family query parameter
with **400 Bad Request** (`QueryParamUnrecognized`). The rule mirrors the spec:
any all-lowercase query-parameter name (`a`–`z` only) is reserved to the JSON:API
family, and only six names are recognized — `fields`, `include`, `sort`, `page`,
`filter`, `profile`. So `?albumSort=...` (the capital `S` is a non-`a-z`
character) is your own implementation-specific parameter and passes through, but
`?sortby=...` — like `?albumsort=...` — is all lowercase, not one of the six, and
is rejected:

```php
\in_array($queryParamName, ['fields', 'include', 'sort', 'page', 'filter', 'profile'], true)
```

Each recognized family is parsed by its own page:
[sparse fieldsets and includes](sparse-fieldsets-and-includes.md) (`fields`,
`include`), [sorts](sorts.md) (`sort`), [pagination](pagination.md) (`page`),
[filters](filters.md) (`filter`), and [profiles](profiles.md) (`profile`). A
malformed value *inside* a recognized
family — for example a non-string `profile` query value — is a separate
`QueryParamMalformed` (`400`).

## Body structure

When a request carries a body, two further checks run before your hydrator sees it.

`validateJsonBody()` is a thin trigger: it asks the request for its parsed body,
which decodes the raw bytes and throws `RequestBodyInvalidJson` (`400`) if they are
not valid JSON. (Reading the body anywhere else triggers the same decode, so this
method just gives callers an explicit entry point.)

`validateTopLevelMembers()` then validates the document's top-level shape against
the JSON:API structure rules. An empty body short-circuits as valid; otherwise:

| Rule | Exception | Status |
| --- | --- | --- |
| at least one of `data` / `errors` / `meta` is present | `RequiredTopLevelMembersMissing` | `400` |
| `data` and `errors` do not coexist | `TopLevelMembersIncompatible` | `400` |
| `included` is absent unless `data` is present | `TopLevelMemberNotAllowed` | `400` |

These are *structural* checks only. Whether the `data` member is a semantically
valid resource for the operation — its `type`, its attributes, its constraints —
is the hydrator and validation layer's job, not negotiation's.

## Profiles flow through; extensions can fail

The `profile` and `ext` parameters look alike but negotiate very differently.

[Profiles](profiles.md) are **advisory**: the spec says a server MUST *ignore* any
profile it does not recognize. So an unrecognized profile is **never** a `406` or
`415` — it flows through untouched at this layer, and the response layer applies
only the profiles the server has actually registered. Profile *emission* (echoing
applied profiles on the response `Content-Type`, `links.profile`, and
`Vary: Accept`) is owned by the response layer, not by negotiation — see
[profiles](profiles.md) and [responses](responses.md).

Extensions demand strict client/server agreement: a client that asserts an `ext`
the server does not support **must** be refused. `negotiate()` validates the
parsed `ext` against the server's supported-extension set:

- An unsupported `ext` on `Content-Type` → **415**.
- An unsupported `ext` on `Accept` → **406**.

The supported set is supplied to `ContentNegotiationMiddleware` (and to
`RequestValidator`) as a variadic of extension URIs, and is **empty by default** —
so by default any `ext` parameter present on the request is rejected:

```php
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;

// Default: no extensions supported — any `ext` is rejected (415 / 406).
new ContentNegotiationMiddleware();

// Declare support for one or more extension URIs.
new ContentNegotiationMiddleware('https://example.com/ext/version-history');
```

No extensions ship in this release, so out of the box any `ext` is rejected. To
support one, register its URI here and matching requests pass negotiation.

## Introspecting what was negotiated

The request object carries the parsed `profile` and `ext` values for code — chiefly
the response layer — that needs to know what the client asked for and what the
server applied. These read-side accessors live on `JsonApiRequestInterface`:

| Accessor | Returns |
| --- | --- |
| `getAppliedProfiles()` / `isProfileApplied()` | profiles asserted on the request `Content-Type` |
| `getRequestedProfiles()` / `isProfileRequested()` | profiles requested on the request `Accept` |
| `getRequiredProfiles()` / `isProfileRequired()` | profiles in the `profile` query parameter |
| `getAppliedExtensions()` | extension URIs asserted on `Content-Type` |
| `getRequestedExtensions()` | extension URIs requested on `Accept` |

`negotiate()` itself uses `getAppliedExtensions()` / `getRequestedExtensions()` to
police extension support; the profile accessors feed the response layer's emission
decisions.

## Response-side validation

`Negotiation\ResponseValidator` mirrors the request rule on the way out — a
defensive check intended for development and CI, not the hot path. It exposes two
methods:

- `validateContentTypeHeader()` — the outgoing `Content-Type` is a valid JSON:API
  media type (`profile` / `ext` parameters only), throwing `MediaTypeUnsupported`
  otherwise.
- `validateJsonBody()` — the body is well-formed JSON (an empty body, as a `204`
  carries, is accepted and yields `null`), throwing `ResponseBodyInvalidJson`
  otherwise; it returns the decoded document for any further inspection.

In normal use you do not call either: the [response layer](responses.md) always
sets a correct `Content-Type`, and the optional
[response validation middleware](middleware.md) wraps these (plus the heavier JSON
Schema check) for dev/CI runs.

## Next

- [Middleware](middleware.md) — where `ContentNegotiationMiddleware` and
  `RequestBodyParsingMiddleware` sit in the chain, and their signatures.
- [Profiles](profiles.md) — declaring, registering, and applying profiles.
- [Errors and exceptions](errors-and-exceptions.md) — how a `415` / `406` / `400`
  is rendered, and the full exception catalogue.
