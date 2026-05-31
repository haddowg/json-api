# Phase 3 — PSR-15 Middleware Suite

## Goal & scope

Ship a suite of PSR-15 middleware classes that implement the JSON:API request lifecycle. The middleware are **owned by a `Server`** (introduced in Phase 4.5 — see below for the Phase 3 placeholder strategy); each server holds its own ordered middleware list and dispatches via `Server::handle($request)`. Framework routing maps URL prefix → server. Core ships the middleware classes themselves; the wiring is done at server-construction time by consumer or framework adapter code.

**In scope:**

- Content negotiation middleware (validates request `Content-Type` / `Accept`, applies negotiated profiles/ext to the request)
- Request body parsing middleware (parses JSON body once, attaches a structured `JsonApiRequest` representation to the PSR-7 request as an attribute)
- Error handling middleware (catches `JsonApiException` and any other configured throwables; **also detects response value objects returned from the inner handler and renders them to PSR-7 responses**; sits at the outermost position)
- A reserved slot in the recommended middleware order for atomic-ops dispatch (atomic ops is a post-1.0 candidate, but the order doc should acknowledge the slot)
- **Per-server middleware ownership.** Middleware take a `Server` (or a Phase 1 `Server`-placeholder shape) in their constructors, not as a request attribute. No `SingleServerMiddleware`; no select-server middleware in core. Server selection is framework routing's job.
- Tests for each middleware in isolation plus integration tests of a full chain assembled inside a `Server`

**Out of scope:**

- The atomic-ops dispatcher middleware itself (post-1.0 candidate)
- Schema validation middleware (Phase 4)
- Framework-specific glue (Symfony bundle, Laravel package, etc.)
- Routing — consumers/framework adapters route to whatever `Server` they choose; the `Server::handle()` method runs that server's chain
- A select-server middleware in core — server selection lives in the routing layer (Symfony route prefixes, hand-rolled router, etc.), not in the JSON:API middleware chain

## Prerequisites

- Phase 2 complete: profile infrastructure and content-negotiation parsing (including the `ext` parser hook) exist, plus the `Paginator`/`Page` rewrite
- Phase 1 typed exception hierarchy is the source of truth for what error middleware catches
- Phase 1 response value objects (`DataResponse` et al) exist and define the rendering contract the error handler middleware completes

## Kick-off

Before writing any implementation code:

1. Read `docs/phase-2-profiles-pagination.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. Confirm the PSR-15 packages are added to `composer.json` (`psr/http-server-middleware`, `psr/http-server-handler`). If not present, add as a first step and update this plan.
3. Decide whether the middleware live under `haddowg\JsonApi\Middleware\` (recommended, matches yin-middleware structure) or another namespace, and record the decision.
4. **Confirm the `Server` placeholder shape from Phase 1 is sufficient for middleware constructor injection in this phase.** Phase 4.5 introduces the full `Server`; Phase 3 middleware constructors take the placeholder, which Phase 4.5 fleshes out without changing the signature. If the placeholder is missing fields the middleware need (e.g. profile registry, base URI), surface this at kick-off and add to Phase 1's task list retroactively.
5. Re-read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
5. Revise the task list as needed and commit the plan revision as a single commit before starting implementation.

## Task list

### Shared scaffolding

- [ ] Add PSR-15 interfaces as runtime dependencies: `psr/http-server-middleware: ^1.0`, `psr/http-server-handler: ^1.0`
- [ ] Decide the middleware base namespace (recommendation: `haddowg\JsonApi\Middleware\`)
- [ ] Define a small shared helper or trait for building JSON:API error responses inside middleware (uses the typed exception → error-document mapping established in Phase 1)
- [ ] Decide how middleware acquire dependencies: constructor injection only (recommended) — no service location, no global state
- [ ] Establish the convention for what PSR-7 request attribute name the parsed JSON:API request representation lives under (e.g. `haddowg\JsonApi\Request::class` as the attribute key, or a string constant). Record in decision log.

### Content negotiation middleware

- [ ] `haddowg\JsonApi\Middleware\ContentNegotiationMiddleware`
- [ ] **Constructor takes a `Server`** (or Phase 1 placeholder shape); reads the profile registry, supported extensions, and any negotiation defaults from it. No request-attribute lookup.
- [ ] Behaviour:
  - On request: validate `Content-Type` (must be `application/vnd.api+json` for request bodies on POST/PATCH/DELETE-with-body); validate `Accept` includes a compatible media type; parse and validate `profile` and `ext` parameters against the profile registry
  - Throws typed exceptions on unsupported media type (415), unacceptable Accept (406), unsupported profile (406), unsupported extension (415 per spec)
  - On response (post-handler): ensure response `Content-Type` is `application/vnd.api+json` with appropriate `profile` / `ext` parameters echoed
- [ ] Configurable: which extensions/profiles to advertise as supported (defaults: all registered on the server)
- [ ] Tests: each rejection path; each success path; profile echoing on response; ext echoing on response (even though no ext is yet applied — atomic operations is a post-1.0 candidate)

### Request body parsing middleware

- [ ] `haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware`
- [ ] Constructor takes PSR-17 factories only; no server dependency (body parsing is JSON:API-agnostic at this layer — content-negotiation already validated the type).
- [ ] Behaviour:
  - Reads the PSR-7 request body once, parses with `json_decode` (using `JSON_THROW_ON_ERROR`)
  - On parse failure throws a typed exception → 400
  - Attaches the parsed JSON:API request representation (the same `JsonApiRequest` that the orchestrator uses) to the PSR-7 request as an attribute
  - Rewinds and preserves the body so downstream middleware/handlers can still read it raw if they want
- [ ] Tests: well-formed body parsed and attached; malformed JSON rejected; empty body handled per spec; request with no body (GET) passes through untouched

### Error handler / response renderer middleware

This middleware does two jobs that share a wraps-everything-and-converts-to-PSR-7 shape. Merging them avoids a fifth middleware.

- [ ] `haddowg\JsonApi\Middleware\ErrorHandlerMiddleware`
- [ ] **Constructor takes a `Server`** plus PSR-17 factories. The server provides base URI, version, default `jsonapi.meta`, and other state needed for rendering.
- [ ] Behaviour:
  - Wraps `$handler->handle($request)` in a `try`/`catch`
  - **Inspects the inner handler's return value.** If it's a JSON:API response value object (`DataResponse`, `MetaResponse`, `RelatedResponse`, `IdentifierResponse`, `ErrorResponse`), renders it against the server to a PSR-7 response. Otherwise (already a PSR-7 response, or a return shape this package doesn't recognise) passes it through unchanged.
  - Catches `JsonApiException` and renders its error document to a PSR-7 response, reading `getErrors()` and `getStatusCode()` (equivalent to constructing an `ErrorResponse` from the exception and rendering it).
  - Catches `\Throwable` and renders a generic 500 error document; whether the original exception's message/trace is included is controlled by a constructor flag (default: false, on for dev)
  - Sets `Content-Type: application/vnd.api+json` on the error response, including the appropriate `ext` and `profile` parameters if negotiation has already attached supported values to the request
- [ ] Configurable: include-debug-info toggle; optional logger (PSR-3) — log non-`JsonApiException` throwables before rendering
- [ ] Tests: typed exception → correct status + body; generic throwable → 500 with redacted body in production mode and verbose body in dev mode; logger receives the throwable when configured; `DataResponse` returned from handler rendered correctly with the server's base URI; `MetaResponse` returned from handler rendered as a meta-only document.

### Atomic-ops middleware placeholder

- [ ] Reserve a documented slot in the recommended middleware order for atomic-ops dispatch (between request body parsing and the handler). Atomic Operations is a post-1.0 candidate; this is purely a documentation note so the order is stable for future work.
- [ ] No code shipped this phase; just docs and a TODO comment in the recommended-order doc

### Recommended order documentation

- [ ] Document the recommended middleware order in `docs/middleware-order.md` (or a similar stub for the docs phase to expand). The order is **per-server** — each `Server` instance holds its own middleware list, typically following this order:
  1. Error handler / response renderer (outermost — catches everything downstream and renders response value objects from the handler)
  2. Content negotiation
  3. Request body parsing
  4. _(Atomic ops dispatch — post-1.0 candidate. Reserved slot. When implemented, this middleware reads the atomic operations array from the request body, constructs multiple `JsonApiOperation` instances, dispatches each through the inner `OperationHandler`, and aggregates the results into an `atomic:results` response. No nested PSR-7 requests involved.)_
  5. Handler — the innermost element. Recommended path: an `OperationHandler` (Phase 1) wrapped in `Psr7ToOperationHandlerAdapter`, which translates the PSR-7 request into a `JsonApiOperation`, invokes the consumer's handler, and renders the returned response value object. Consumers who prefer PSR-15 directly can supply a `RequestHandlerInterface` instead — the error handler renders whatever response value object it returns, or passes through a PSR-7 response unchanged.
- [ ] Explain why each precedes/follows another (e.g. content negotiation must run before body parsing so body parsing can reject for content-type mismatch; error handler must be outermost so it catches everything and renders return values regardless of where they came from; the atomic-ops slot sits *after* body parsing because it needs the parsed body to enumerate operations, and *before* the operation handler because it controls dispatch).
- [ ] Note that the order is a recommendation, not a constraint: a server can be constructed with any middleware list it wants. The error handler being outermost is the only firm recommendation.

### Integration tests

- [ ] Build a small end-to-end test that constructs a `Server` (or Phase 1 placeholder) with the standard middleware chain, dispatches via `Server::handle($request)`, and exercises:
  - Happy path (GET with valid Accept; **inner handler is an `OperationHandler` wrapped in `Psr7ToOperationHandlerAdapter`**; returns a `DataResponse`; rendered to PSR-7)
  - Happy path with the handler being a bare PSR-15 `RequestHandlerInterface` returning a fully-rendered PSR-7 response (passes through unchanged)
  - Happy path with the handler being a bare PSR-15 `RequestHandlerInterface` returning a response value object (rendered by the error handler middleware)
  - 415 for wrong Content-Type
  - 406 for unsupported Accept profile
  - 400 for malformed JSON body
  - 500 with redacted body when handler throws unexpectedly
- [ ] **Multi-server integration test.** Construct two `Server` instances with different middleware lists (e.g. one with `RequestValidationMiddleware` planned for Phase 4, one without), confirm each runs its own chain and selecting between them is the test's routing logic, not a middleware concern. Even with Phase 4 not yet implemented, the test verifies the per-server-middleware ownership pattern works.
- [ ] **Programmatic dispatch sanity test.** Confirm an operation can be constructed and dispatched through `$server->dispatch($operation)` without going through the PSR-15 chain at all — bypasses middleware, returns the response value object directly. This is the post-1.0 atomic-ops dispatcher's mechanism, exercised early.
- [ ] Use `nyholm/psr7` and a minimal PSR-15 request handler from the same ecosystem (or write a 10-line one inline)

### Spec compliance update

- [ ] Update `docs/spec-compliance.md` rows for content negotiation, error responses, and any other spec sections this phase touches

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| _yyyy-mm-dd_ | _(example: PSR-7 request attribute key is the FQCN `haddowg\JsonApi\Request\JsonApiRequest::class`)_ | _(rationale)_ | _(this phase / future phases)_ |

## Open questions

- Should the middleware suite expose a single `JsonApiMiddleware` aggregate (composes the three into a single PSR-15 middleware) for consumers who don't want to manage ordering? Lean: no — composition is the consumer's job and aggregation hides the order. But worth deciding explicitly.
- Where do PSR-17 factories come from in middleware? Constructor inject `ResponseFactoryInterface` and `StreamFactoryInterface`, or take a single combined "psr-17 factory" object? Lean: separate, matches the PSR.
- Should `ErrorHandlerMiddleware` include the throwable in the error document `meta.exception` when in dev mode, or use the existing yin-style top-level pattern? Decide before implementation.
- Should the body-parsing middleware enforce a max-size limit (DoS protection) or leave that to upstream infrastructure? Lean: leave to upstream; document the recommendation.
- For HTTP methods that the JSON:API spec doesn't define request bodies for (e.g. GET, DELETE without body), does body parsing middleware short-circuit unconditionally, or check the `Content-Length`/`Content-Type` headers? Decide before implementation.

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. The three middleware (`ContentNegotiationMiddleware`, `RequestBodyParsingMiddleware`, `ErrorHandlerMiddleware`) exist under the agreed namespace.
3. Each middleware has unit tests covering its happy path and each spec-mandated rejection path.
4. `ContentNegotiationMiddleware` and `ErrorHandlerMiddleware` take a `Server` (or Phase 1 placeholder) in their constructors. No middleware reads server state from a request attribute. No `SingleServerMiddleware` or select-server middleware ships in core.
5. `ErrorHandlerMiddleware` renders response value objects (`DataResponse`, `MetaResponse`, etc.) returned from the inner handler; PSR-7 responses returned from the inner handler pass through unchanged. Unit + integration tests cover both paths.
6. The integration test wiring all three middleware inside a `Server` (or placeholder) passes, including the multi-server case demonstrating that two servers with different middleware lists can coexist.
7. PSR-15 dependencies are declared in `composer.json`.
8. PHPStan level 9 passes; CI matrix green; spec-tagged tests pass.
9. `docs/spec-compliance.md` updated for affected sections.
10. `docs/middleware-order.md` (or chosen stub) documents the recommended per-server order including the reserved atomic-ops slot, plus a note that ordering is per-server.
11. `CLAUDE.md` updated with a pattern entry for PSR-15 middleware (constructor injection of `Server`, error-throwing convention, response-mutation pattern, response-value-object rendering by the error handler, request-attribute conventions).

### Verification plan

```bash
composer install
composer test
composer phpstan
composer cs-check

# Middleware-specific spec coverage
vendor/bin/phpunit --group spec:content-negotiation
vendor/bin/phpunit --group spec:errors

# Lowest-deps run
composer update --prefer-lowest --prefer-stable
composer test
```

Manual review:

- Walk through the integration test, confirm the assertion set covers each rejection path with the spec-correct status code.
- Wire the three middleware in a throwaway script against `nyholm/psr7`'s `ServerRequestCreator` and a simple closure handler; confirm a malformed body, bad accept, and successful request all behave correctly end-to-end without needing the rest of the orchestrator.

## Handover output

Before declaring the phase complete, produce the following for Phase 4:

1. **Status table update** in `docs/PLAN.md` — Phase 3 → `Complete`, Phase 4 → `Ready`.
2. **Phase 4 plan review** — `docs/phase-4-validation.md` already exists as a pre-drafted plan. Read it end-to-end against this phase's decision log and current repository state. Confirm it still covers at minimum:
   - Integration with `opis/json-schema` as an optional dev dependency
   - Source of the JSON:API JSON Schema (official, vendored, fetched at runtime — decide and document)
   - Two validation pathways: request body validation and response body validation
   - How validation surfaces as middleware (likely two new optional middleware) and how they slot into the recommended order from this phase
   - Strategy for validating profile-defined keywords (a profile can declare its own schema fragment that augments validation — design the hook). The hook does not need to be generalised for the `ext` parameter at this time; atomic operations is a post-1.0 candidate and can extend the hook when scheduled.
   - Test plan including spec sections covered by validation
   - Append revisions to the plan as a single commit; the actual kick-off revision happens at the start of Phase 4, but corrections forced by Phase 3 decisions belong here.
3. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 4.
4. **Decision log finalised** — phase-local decisions captured here; any cross-phase decisions promoted to `PLAN.md`.

## Phase 2 reconciliation notes (appended at Phase 2 close)

These corrections are forced by Phase 2 decisions; fold them into the plan at the
Phase 3 kick-off revision (see `docs/phase-2-profiles-pagination.md` decision log).

- **Profiles are advisory — the content-negotiation middleware MUST NOT reject
  unrecognized profiles.** A server ignores any profile it does not recognize.
  The middleware's only negotiation *rejection* concerns the `ext` parameter:
  wrap `Negotiation\RequestValidator(string ...$supportedExtensions)`, which
  throws `MediaTypeUnsupported` (415) / `MediaTypeUnacceptable` (406) for an
  unsupported extension. Profiles flow through untouched.
- **Profile application is already done in the response layer**, not in
  middleware. `Response\AbstractResponse::toPsrResponse()` applies the
  server-registered requested profiles (echoing the `Content-Type` `profile`
  parameter, writing `links.profile`, running each profile's
  `finalizeDocument()` hook, and setting `Vary: Accept`). The error-handling
  middleware, which renders response value objects, therefore gets profile
  emission for free — it does not re-implement it. The middleware only needs to
  ensure the active `Server` (carrying `profiles()`) is passed to
  `toPsrResponse()`.
- **`Server` exposes `profiles(): ProfileRegistry`.** The middleware suite reads
  the profile registry from the `Server`, consistent with the Phase 2 decision
  that the registry is per-server and injected (no global registry). This
  supersedes any plan wording implying a free-standing profile registry passed
  separately into middleware.
- **`ext` parsing is wired but not dispatched.** No atomic-ops middleware ships;
  the reserved slot remains reserved. The body-parsing / negotiation middleware
  should leave `getRequestedExtensions()`/`getAppliedExtensions()` reachable on
  the request it forwards, so the post-1.0 atomic-ops dispatcher can consume them
  without re-parsing.
- **Pagination needs no middleware.** `DataResponse::fromPage($page)` renders
  pagination links/meta in the response layer; no middleware participates in
  pagination. Disregard any earlier implication otherwise.
