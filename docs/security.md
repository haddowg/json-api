# Security

This page tells you what `haddowg/json-api` guarantees against malicious input
and what your deployment must own. Read it before you put a JSON:API server in
front of untrusted traffic.

The library is a server-side request/response engine: it parses untrusted
request documents and headers, and produces response documents. It does **not**
handle authentication, authorisation, rate limiting, or transport security —
those belong to your application and the middleware you run around this one. The
sections below split cleanly into [what the library guarantees](#what-the-library-guarantees)
and [what you must do](#what-you-must-do).

## What the library guarantees

These properties were reviewed for the 1.0 release and are covered by the test
suite.

### Bounded body parsing

Every JSON body is decoded with an explicit depth limit and
`JSON_THROW_ON_ERROR`:

```php
\json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
```

- The depth bound (512, PHP's default) means a deeply nested document is
  **rejected** rather than allowed to exhaust the stack — a decode past the limit
  throws, and the request layer turns that into a `400` via
  [`RequestBodyInvalidJson`](errors-and-exceptions.md).
- `JSON_THROW_ON_ERROR` is passed at every decode site, so malformed JSON never
  produces a silent `null` that flows downstream as data.

The depth limit caps *nesting*, not *size*. A large but shallow body (a huge
`included` array, say) is still read in full to decode it. That is why a
body-size cap is on your side of the line — see
[Put a body-size limit in front of the application](#what-you-must-do).

### Safe header parsing

The `Accept` and `Content-Type` parsers validate media-type parameters with a
**linear character scanner** — it walks the header byte by byte, tracking only
whether it is inside a quoted value, and splits on unquoted commas. The
parameter-name checks use regular expressions built from **disjoint character
classes with no nested quantifiers**, so there is no input that drives them into
catastrophic backtracking or an unbounded loop. A header that violates the
JSON:API media-type rule is rejected up front — an unsupported `Content-Type`
with a `415` ([`MediaTypeUnsupported`](errors-and-exceptions.md)), an unacceptable
`Accept` with a `406` — before any operation runs. See
[content negotiation](content-negotiation.md) for the negotiation rules these
parsers enforce.

### Debug-gated error responses

The error handler is debug-gated and **defaults to production-safe**:

```php
new \haddowg\JsonApi\Middleware\ErrorHandlerMiddleware($server);              // $debug defaults to false
new \haddowg\JsonApi\Middleware\ErrorHandlerMiddleware($server, debug: true); // opt in for local/dev only
```

- With debug **off** (the default), an unexpected throwable renders a generic
  `500` carrying only `status` and `title` — no exception message, class, file,
  line, stack trace, or environment.
- With debug **on**, the message, class, file, line and trace are attached to the
  error object's `meta`, and stack-frame **arguments are stripped** (`args` is
  unset from every frame) so argument values — which may contain credentials,
  tokens, or other secrets — are never serialised.

Typed exceptions render their own spec-defined `status` / `code` / `title` /
`detail`; those strings are library-controlled. The full mapping and the debug
contract live in [errors and exceptions](errors-and-exceptions.md).

### Allow-list write hydration (no mass-assignment)

Hydration is **allow-list based**: it walks the resource's *declared* field
inventory and reads each declared field from the body — it never iterates the
client's keys. This is a structural guard against mass-assignment.

- **Undeclared members are silently ignored.** An attribute or relationship the
  resource did not declare (the classic over-post of an `isAdmin` or `secretFlag`
  flag) is never read and never written. In the music catalog,
  [`AlbumResource`](../examples/music-catalog/src/Resource/AlbumResource.php) does
  not declare a `secretFlag`, so a `POST` carrying one creates the album with that
  member dropped:

  ```php
  $response = $this->post('/albums', [
      'data' => [
          'type' => 'albums',
          'attributes' => [
              'title' => 'A Moon Shaped Pool',
              'secretFlag' => true,
          ],
      ],
  ]);

  // 201 — and the created album has no `secretFlag`:
  self::assertArrayNotHasKey('secretFlag', $attributes);
  ```

  (from [`WritesTest`](../examples/music-catalog/tests/WritesTest.php))

- **Read-only fields are skipped.** Within the declared set, a field marked
  `readOnly()` / `readOnlyOnCreate()` / `readOnlyOnUpdate()` is skipped for that
  operation — including nested `Map` children and relationships in a whole-resource
  write. `AlbumResource`'s `averageRating` is `readOnly()`, so a client value in
  the body is ignored and the new album keeps its domain default. The
  [`PlaylistResource`](../examples/music-catalog/src/Resource/PlaylistResource.php)
  `slug` is `readOnly()` for the same reason — it is derived server-side by the
  custom hydrator, never client-written.

- **A client-generated `id` is rejected unless opted in.** A `POST` that supplies
  its own `id` is rejected with `ClientGeneratedIdNotSupported` unless the resource
  overrides `acceptsClientGeneratedId()` — as `PlaylistResource` does for its
  client-generated UUID. See [client-generated ids](ids.md).

This is a structural guard, not per-user field authorisation: it stops a client
writing fields the *resource* never declared, but it does not decide whether
*this* user may write a field the resource *does* declare. That decision is yours
— see [Treat hydrated input as untrusted](#what-you-must-do). The hydration
mechanics are covered in [hydrators](hydrators.md).

### Reflected-input caveat

A few errors deliberately echo the **requester's own input** back to that same
requester:

- a media-type error includes the offending `Accept` / `Content-Type` value in
  its `detail`;
- a malformed-JSON `400` includes the raw request body under `meta.original`.

This is reflection of the caller's own request — it helps the caller see exactly
what they sent — not a leak of server state, secrets, or another tenant's data.
If you proxy or log these error documents, treat them as carrying the original
request payload.

## What you must do

The library secures the JSON:API surface; the deployment around it is yours.

- **Put a body-size limit in front of the application.** The depth bound stops
  pathological *nesting*, but the library still reads the full body to decode it.
  Cap request body size at the web server / reverse proxy (or a PSR-15
  middleware) so a large payload cannot exhaust memory before it reaches the
  decoder.
- **Keep debug off in production.** The default is already off; make sure you do
  not pass `debug: true` (or wire it to an app debug flag that is somehow true in
  prod).
- **Authenticate and authorise upstream.** This library does not check identity
  or permissions. Run your auth middleware *outside* the JSON:API chain so an
  unauthenticated or unauthorised request never reaches an operation handler.
- **Treat hydrated input as untrusted.** Hydration maps a validated document onto
  your domain object via the allow-list above, but it does not enforce
  business-level authorisation ("may *this* user set *this* field"). Apply those
  checks in your handler or hydrator — see [hydrators](hydrators.md) and
  [validation](constraints.md).
- **The schema-validation middleware is a dev/CI aid, not a firewall.** The
  optional `opis/json-schema`-backed [structural validation](schema-validation.md)
  tightens spec conformance during development; it is opt-in and not a substitute
  for the input handling above.

## Reporting a vulnerability

Report suspected vulnerabilities privately to the maintainer rather than opening
a public issue, so a fix can be prepared before disclosure.

## Next / See also

- [Errors and exceptions](errors-and-exceptions.md) — the typed-exception status
  map and the full debug-meta contract.
- [Content negotiation](content-negotiation.md) — the `415`/`406` rules the
  header parsers enforce.
- [Hydrators](hydrators.md) and [operations](operations.md) — how the allow-list
  hydration runs, and where to add your own authorisation.
- [Schema validation](schema-validation.md) — the opt-in structural validator and
  why it is a dev/CI aid.
