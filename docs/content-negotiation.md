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
supported-extension set, and each method maps onto one check:

| Method | Checks | On failure |
| --- | --- | --- |
| `negotiate()` | `Content-Type` / `Accept` media-type parameters + extension support, and optionally a JSON:API `Content-Type` | `415` / `406` |
| `negotiateContentType()` | a raw-bodied action's `Content-Type` against the media types it accepts | `415` |
| `validateQueryParams()` | the JSON:API query-parameter families | `400` `QueryParamUnrecognized` |
| `validateJsonBody()` | the request body is well-formed JSON | `400` `RequestBodyInvalidJson` |
| `validateTopLevelMembers()` | the body's top-level `data` / `errors` / `meta` structure | `400` |

The shipped middleware split these across two stages:
[`ContentNegotiationMiddleware`](middleware.md) runs `negotiate()` (strictly on a
write) + `validateQueryParams()` on every request, and `RequestBodyParsingMiddleware` runs
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

The optional `q` weight is not a media-type parameter and is ignored on `Accept`,
and so are any accept-extension parameters that follow it — neither participates in
the JSON:API media-type check. A header that does not assert the JSON:API media type
at all (absent, `*/*`, a
different subtype), or asserts it with no parameters, is acceptable — negotiation
only polices the JSON:API media type's own parameters. The parser is quote-aware,
so a comma inside a quoted `profile` / `ext` value never splits one media-type
instance into two.

## A write must say it is JSON:API

The parameter rule above lets a `Content-Type` through when it does not name
JSON:API at all, or is missing. That is right for a `GET`, which has no body, but a
write carries a document, and a document sent as `text/plain` or as a form is not one.
Pass `strictContentType: true` to `negotiate()` and the header must be present and be
`application/vnd.api+json`, with only `ext` / `profile` parameters, or the request
is a **415** (`MediaTypeUnsupported`):

```php
$validator->negotiate($request, strictContentType: true);
```

`ContentNegotiationMiddleware` does this for every `POST`, with or without a body,
and for any other unsafe method (`PATCH`, `PUT`, `DELETE`) that has a body. A
bodyless `DELETE`, such as a resource delete, needs no `Content-Type`. A framework integration applies the
same rule to its own routes: CRUD and relationship writes, and custom actions whose
input is a JSON:API document or nothing at all.

A custom action that takes a raw body accepts other media types, so it skips the
JSON:API assertion and matches the body against the media types it declares instead:

```php
$validator->negotiate($request, requireJsonApiContentType: false);
$match = $validator->negotiateContentType($request, ['text/csv', 'image/*']);
```

`negotiateContentType()` compares `type/subtype` only, so a `charset` or multipart
`boundary` never stops a match, and it picks the most specific entry: `image/png`
before `image/*` before `*/*`. It is a `415` when the header is missing, names two
media types, or matches nothing. The three media types a browser sends cross-origin
without a CORS preflight, `text/plain`, `multipart/form-data` and
`application/x-www-form-urlencoded`, are a `415` too unless the action lists them by
name: `text/*` or `*/*` never opts an action into them. The lower-level
`ContentTypeMatcher::match()` returns the same `ContentTypeMatch` without throwing,
with `corsSafelisted` and `isExplicit()` for an integration that wants its own rule.

An action that can answer in several media types, a JSON:API document or a CSV export
say, picks one from `Accept` with `AcceptNegotiator::negotiate()`; see
[raw action responses](openapi.md#raw-action-responses).

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

## Strict query-parameter validation (on by default)

The baseline above only rejects an **all-lowercase** unrecognized name. The spec
permits, but does not require, a server to *also* reject a **well-named** custom
parameter (one carrying a non-`a-z` character) that it does not recognize —
`?albumSort=...` passes the naming rule but the server may have no such parameter.
Tolerating it silently means a client typo (`?withCont=comments`, a misspelled
`?relatedQ[...]`) is dropped and the request returns a wrong-but-`200` result.

`Server` closes that gap with **strict query-parameter validation, on by
default**. Before the operation handler runs, `Server::dispatch()` rejects any
query parameter whose **family base name** is not recognized with a `400`
`QueryParamUnrecognized` (`source.parameter` is the offending base). The
recognized set for a request is:

- the six reserved JSON:API families (`fields`, `include`, `sort`, `page`,
  `filter`, `profile`) — their *internal* key validation (an unknown
  `filter`/`sort` key, a malformed `page`) is unchanged and still each family's
  own job; strict mode only adds the **family**-level check;
- the always-on `withCount` custom family;
- the reserved keywords of every registered [profile](profiles.md) the request
  **negotiated** — so the relationship-queries profile's `relatedQuery` / `rQ`
  families are recognized only when that profile's URI is in the `Accept`
  `profile` parameter, and are rejected otherwise;
- any host-registered custom families.

```php
$server = Server::make()
    ->withCustomQueryParameter('withTrashed') // recognize your own param
    // ->withStrictQueryParameters(false)      // opt out: ignore unknown params
;
```

`withStrictQueryParameters(false)` restores the tolerant behaviour (an
unrecognized family is silently ignored). A host registers its own
implementation-specific families with `withCustomQueryParameter(...)`; each should
carry a non-`a-z` character to satisfy the spec's custom-parameter naming rule.

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
applied profiles on the response `Content-Type`, `jsonapi.profile`, and
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
