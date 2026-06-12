# Content negotiation

JSON:API pins a single media type — `application/vnd.api+json` — and constrains
which parameters it may carry. `haddowg/json-api` enforces those rules on the
request before your handler runs and emits the matching parameters on the
response automatically. The work is split between two thin, stateless validators:
`Negotiation\RequestValidator` (driven by [`ContentNegotiationMiddleware`](middleware.md))
and `Negotiation\ResponseValidator`. This page describes what they check, how the
`profile` and `ext` parameters are treated, and why an unrecognized profile is
never an error.

## The media type and its parameters

Per JSON:API 1.1, the media type `application/vnd.api+json` **MUST NOT** carry any
media-type parameter other than `ext` or `profile`. `@internal Request\MediaType`
applies that rule, but the **`Content-Type` and `Accept` rules differ**, as the spec
requires:

- **`Content-Type`** (`MediaType::isValid()`, also the response-side check) — the
  single media type must carry only `ext` / `profile` parameters; any other parameter
  (for example a stray `charset`) is rejected with **415 Unsupported Media Type**
  (`MediaTypeUnsupported`).
- **`Accept`** (`MediaType::accepts()`) — rejected with **406 Not Acceptable**
  (`MediaTypeUnacceptable`) **only when every** `application/vnd.api+json` instance
  carries a forbidden parameter. A single conforming instance (e.g. in
  `application/vnd.api+json; charset=utf-8, application/vnd.api+json`) makes the header
  acceptable, and the optional `q` weight is not a media-type parameter — it is ignored.

A header that does not assert the JSON:API media type at all, or asserts it with
no parameters, is acceptable — negotiation only polices the JSON:API media type's
own parameters. The parser is quote-aware, so a comma inside a quoted `profile` /
`ext` value does not split one media-type instance into two.

## Query parameters

Alongside the media-type checks, the request validator validates the request's
query parameters (`validateQueryParams()`), rejecting an unrecognized
JSON:API-family query parameter with **400 Bad Request**
(`QueryParamUnrecognized`). The standard families — `fields`, `include`, `sort`,
`page`, `filter`, `profile` — are recognized.

## Profiles are advisory

[Profiles](profiles.md) are negotiated through the `profile` media-type parameter
(and the `profile` query parameter), but they are **advisory**: the spec says a
server MUST *ignore* any profile it does not recognize. So an unrecognized profile
is **never** a 406 or 415 — it simply flows through untouched, and the response
layer applies only the profiles the server has actually registered. This is the
key distinction from extensions, below.

> The response layer — not the negotiation middleware — owns profile *emission*.
> When a response applies one or more profiles it advertises them on the response
> `Content-Type` `profile` parameter and in top-level `links.profile`, and sets
> `Vary: Accept`. See [Profiles](profiles.md) and [Responses](responses.md).

## Extensions (`ext`)

Extensions, unlike profiles, demand strict client/server agreement: a client that
asserts an `ext` the server does not support must be refused. The negotiation
layer parses the `ext` parameter and validates it against the server's
supported-extension set:

- An unsupported `ext` on `Content-Type` → **415**.
- An unsupported `ext` on `Accept` → **406**.

The supported set is supplied to `ContentNegotiationMiddleware` as a variadic of
extension URIs, and is **empty by default** — so by default any `ext` parameter
present on the request is rejected:

```php
use haddowg\JsonApi\Middleware\ContentNegotiationMiddleware;

// Default: no extensions supported — any `ext` is rejected (415 / 406).
new ContentNegotiationMiddleware();

// Declare support for one or more extension URIs.
new ContentNegotiationMiddleware('https://example.com/ext/version-history');
```

No extensions are supported in this release, so any `ext` parameter is rejected
(415 on `Content-Type`, 406 on `Accept`). To support an extension, register its
`ext` URI here and the matching requests pass negotiation. The request exposes
`getRequestedExtensions()` / `getAppliedExtensions()` for code that wants to
inspect what was asked for.

## Response-side validation

`Negotiation\ResponseValidator` mirrors the request rule on the way out: it
checks that an outgoing response's `Content-Type` is a valid JSON:API media type
(profile/ext parameters only) and that the body is well-formed JSON (an empty body
— e.g. a `204` — is accepted). In normal use you do not call it directly; the
[response layer](responses.md) always sets a correct `Content-Type`, and the
optional [response validation middleware](middleware.md) does the heavier JSON
Schema check.

## Related pages

- [Profiles](profiles.md) — declaring, registering, and applying profiles.
- [Middleware](middleware.md) — `ContentNegotiationMiddleware` and the suite.
- [Errors](errors.md) — how a 415 / 406 / 400 is rendered.
