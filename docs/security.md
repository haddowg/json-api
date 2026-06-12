# Security

This page covers the security posture of `haddowg/json-api` and the steps a
consumer should take when deploying it. The library is a server-side request/
response engine: it parses untrusted request documents and headers, and produces
response documents. It does **not** handle authentication, authorisation, rate
limiting, or transport security — those belong to your application and the
middleware you run around this one.

## What the library guarantees

These properties were reviewed for the 1.0 release and are covered by the test
suite.

### Bounded body parsing

Every JSON body is decoded with an explicit depth limit and
`JSON_THROW_ON_ERROR`:

```php
\json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
```

- The depth bound (512, PHP's default) means a deeply nested document is
  **rejected** rather than allowed to exhaust the stack — a decode past the limit
  throws, and the request layer turns that into a `400` via
  `RequestBodyInvalidJson`.
- `JSON_THROW_ON_ERROR` is passed at every decode site, so malformed JSON never
  produces a silent `null` that flows downstream as data.

The depth limit caps *nesting*, not *size*. A large but shallow body (e.g. a huge
`included` array) is still read in full. See the body-size recommendation below.

### Safe header parsing

The `Accept` / `Content-Type` parsers (`Request\MediaType`) split and validate
media-type parameters with a linear scanner and a non-backtracking regex
(disjoint character classes, no nested quantifiers). Malformed or adversarial
header values cannot trigger catastrophic backtracking or an unbounded loop; a
header that violates the JSON:API media-type rule is rejected with `415`/`406`.

### No information leakage in error responses

`ErrorHandlerMiddleware` is debug-gated and **defaults to production-safe**:

```php
new ErrorHandlerMiddleware($server);            // $debug defaults to false
new ErrorHandlerMiddleware($server, debug: true); // opt in for local/dev only
```

- With debug **off** (the default), an unexpected throwable renders a generic
  `500` carrying only `status` and `title` — no exception message, class, file,
  line, stack trace, or environment.
- With debug **on**, the message, class, file, line and trace are attached to the
  error object's `meta`, and stack-frame **arguments are stripped** so argument
  values (which may contain secrets) are never serialised.

Typed `JsonApiExceptionInterface`s render their own spec-defined `status`/`code`/`title`/
`detail`; those strings are library-controlled. A few errors deliberately echo the
**requester's own input** back to that same requester — a media-type error includes the
offending `Accept`/`Content-Type`, and a malformed-JSON error includes the raw body in
`meta` — which is reflection of the caller's own request, not a leak of server state,
secrets, or another tenant's data.

### Allow-list write hydration (no mass-assignment)

Hydration is **allow-list based**: it walks the resource's *declared* field inventory
and reads each declared field from the body — it never iterates the client's keys. An
attribute or relationship the resource did not declare (the classic `isAdmin` over-post)
is silently **ignored**, never written. Within the declared set, a field marked
`readOnly()` / `readOnlyOnCreate()` / `readOnlyOnUpdate()` is skipped for that operation
(including nested `Map` children and relationships in a whole-resource write), and a
client-generated `id` is rejected unless the resource opts in. This is a structural
guard against mass-assignment — but it is **not** per-user field authorisation (see
"Treat hydrated input as untrusted" below).

## What you must do

The library secures the JSON:API surface; the deployment around it is yours.

- **Put a body-size limit in front of the application.** The depth bound stops
  pathological *nesting*, but the library still reads the full body to decode it.
  Cap request body size at the web server / reverse proxy (or a PSR-15 middleware)
  so a large payload cannot exhaust memory before it reaches the decoder.
- **Keep debug off in production.** The default is already off; make sure you do
  not pass `debug: true` (or wire it to an app debug flag that is true in prod).
- **Authenticate and authorise upstream.** This library does not check identity or
  permissions. Run your auth middleware *outside* the JSON:API chain so an
  unauthorised request never reaches an operation handler.
- **Treat hydrated input as untrusted.** Hydration maps a validated document onto
  your domain object, but it does not enforce business-level authorisation (e.g.
  "may this user set this field"). Apply those checks in your handler.
- **Validation middleware is a dev/CI aid, not a runtime firewall.** The optional
  `opis/json-schema`-backed validation middleware tightens spec conformance during
  development; it is opt-in and not a substitute for the input handling above.

## Reporting a vulnerability

Report suspected vulnerabilities privately to the maintainer rather than opening a
public issue, so a fix can be prepared before disclosure.
