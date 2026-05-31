# Phase 3 — PSR-15 Middleware Suite

## Goal & scope

Ship a suite of PSR-15 middleware classes that implement the JSON:API request lifecycle. The middleware are **owned by a `Server`** (introduced in Phase 4.5 — see below for the Phase 3 placeholder strategy); each server holds its own ordered middleware list and dispatches via `Server::handle($request)`. Framework routing maps URL prefix → server. Core ships the middleware classes themselves; the wiring is done at server-construction time by consumer or framework adapter code.

**In scope:**

- Content negotiation middleware (validates request `Content-Type` / `Accept` and `ext`; profiles flow through as advisory)
- Request body parsing middleware (forces a single JSON decode when a body is present and swaps the parsed `JsonApiRequest` down the chain — see kick-off decision; no request attribute)
- Error handling middleware (catches `JsonApiException` and any other throwable, renders the resulting `ErrorResponse`; passes a successful PSR-7 response through unchanged; sits at the outermost position. It does **not** render VOs returned from the inner handler — PSR-15 forbids a handler returning a non-`ResponseInterface`; the adapter renders consumer VOs. See kick-off decision.)
- A reserved slot in the recommended middleware order for atomic-ops dispatch (atomic ops is a post-1.0 candidate, but the order doc should acknowledge the slot)
- **Per-server middleware ownership.** `ErrorHandlerMiddleware` takes a `Server` (or Phase 1 placeholder); `ContentNegotiationMiddleware` takes its supported-`ext` config (revised at kick-off). No middleware reads server state from a request attribute. No `SingleServerMiddleware`; no select-server middleware in core. Server selection is framework routing's job.
- Tests for each middleware in isolation plus an integration test of a full chain (assembled by hand over a `StubServer` + inline dispatcher; the concrete `Server` is Phase 4.5)

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

- [x] Add PSR-15 interfaces as runtime dependencies: `psr/http-server-middleware: ^1.0`, `psr/http-server-handler: ^1.0` (+ `psr/log: ^3.0` for the optional error-handler logger)
- [x] Middleware base namespace = `haddowg\JsonApi\Middleware\`
- [x] Error responses are built via the existing `Response\ErrorResponse` (`fromException()` / `fromErrors()`) rendered through `toPsrResponse($server, $request)` — no new helper/trait; the typed-exception → error-document mapping already lives on the exceptions + `ErrorResponse`
- [x] Middleware acquire dependencies by **constructor injection only** — no service location, no global state
- [x] **No request-attribute key for the parsed request** — the parsed `JsonApiRequest` is swapped into the chain (passed as the request to `$handler->handle()`); downstream `instanceof JsonApiRequestInterface` picks it up. The `Target::class` routing attribute is unchanged.

### Content negotiation middleware

- [x] `haddowg\JsonApi\Middleware\ContentNegotiationMiddleware`
- [x] **Constructor: `__construct(string ...$supportedExtensions)`** (no `Server` — see kick-off decision revising criterion 4). Wraps `Negotiation\RequestValidator(...$supportedExtensions)`. Wraps the incoming request in `JsonApiRequest` (idempotent) and passes it down the chain. No request-attribute lookup. Request-side only — profiles are advisory and flow through untouched (application happens in the response layer).
- [x] Behaviour:
  - On request: validate `Content-Type` and `Accept` media-type parameters (only `ext`/`profile` permitted); negotiate `ext` against the supported set; validate query params. Profiles are **advisory** — never rejected.
  - Throws typed exceptions on unsupported media type (415), unacceptable Accept (406), unsupported `ext` (415 on Content-Type / 406 on Accept), and unrecognized query params
  - No response-side (post-handler) step — Content-Type/profile/`Vary` echoing is owned by the response layer (`toPsrResponse()`), which the error handler drives
- [x] Configurable: the supported `ext` URIs (constructor variadic; default none → any `ext` rejected)
- [x] Tests: each rejection path; each success path; profile echoing on response; ext echoing on response (even though no ext is yet applied — atomic operations is a post-1.0 candidate)

### Request body parsing middleware

- [x] `haddowg\JsonApi\Middleware\RequestBodyParsingMiddleware`
- [x] **Constructor takes nothing** — body parsing never builds a response (it throws typed exceptions the error handler renders) and propagates the request via the swap-down-chain decision, not an attribute, so no PSR-17 factories are needed.
- [x] Behaviour:
  - Wraps the incoming request in `JsonApiRequest` (idempotent — no-op if content negotiation already wrapped it)
  - **Only when a body is present** (skip GET / empty body), forces `getParsedBody()`, which decodes the raw body with `JSON_THROW_ON_ERROR` and surfaces `RequestBodyInvalidJson` (→ 400) early
  - The decode preserves the raw body stream (the wrapper reads `(string) getBody()` without consuming the parsed-body slot), so downstream can still read it
  - Passes the wrapped request down the chain
- [x] No max-body-size limit in core (delegated to upstream infrastructure; documented)
- [x] Tests: well-formed body parsed and reachable downstream; malformed JSON rejected (400); empty body handled per spec; request with no body (GET) passes through untouched

### Error handler / response renderer middleware

This middleware does two jobs that share a wraps-everything-and-converts-to-PSR-7 shape. Merging them avoids a fifth middleware.

- [x] `haddowg\JsonApi\Middleware\ErrorHandlerMiddleware`
- [x] **Constructor: `__construct(ServerInterface $server, bool $debug = false, ?LoggerInterface $logger = null)`.** The server provides base URI, version, default `jsonapi.meta`, the PSR-17 factories (`responseFactory()`/`streamFactory()`), and the profile registry — all reached through `ServerInterface`, so no separate factory injection.
- [x] Behaviour:
  - Wraps `$handler->handle($request)` in a `try`/`catch` and returns the PSR-7 response unchanged on success
  - **Does not inspect the return value for response VOs.** PSR-15 `RequestHandlerInterface::handle()` is typed `: ResponseInterface` and the response VOs deliberately do not implement it, so a conforming handler can only ever return a PSR-7 response — consumer VOs are rendered by `Psr7ToOperationHandlerAdapter` (the recommended innermost handler), which returns PSR-7. The only VO the error handler itself renders is the `ErrorResponse` it builds for a caught throwable. (See kick-off decision.)
  - Catches `JsonApiException` and renders it via `ErrorResponse::fromException($e)->toPsrResponse($server, $request)` (reads `getErrors()` / `getStatusCode()`).
  - Catches `\Throwable` and renders a generic 500 error document. Mapping mirrors `laravel-json-api/exceptions`: `title='Internal Server Error'`, `status='500'`, `code=(string)getCode()` when non-zero; when the `$debug` flag is on, `detail=`the throwable message and the error object's `meta` carries `{exception: class, file, line, trace}`. With `$debug` off, `detail` is a generic non-leaking string and no `meta` debug payload is emitted.
  - The `application/vnd.api+json` Content-Type (and any profile echoing) is applied by the response layer's `toPsrResponse()`.
- [x] Configurable: constructor `bool $debug = false`; optional `?\Psr\Log\LoggerInterface` — log non-`JsonApiException` throwables before rendering
- [x] Tests: typed exception → correct status + body; generic throwable → 500 with redacted body in production mode and verbose body (`detail` + `meta.exception`) in dev mode; logger receives the throwable when configured; a successful PSR-7 response from the handler passes through unchanged.

### Aggregate middleware

- [x] `haddowg\JsonApi\Middleware\JsonApiMiddleware` — a convenience `MiddlewareInterface` that composes `ErrorHandlerMiddleware` → `ContentNegotiationMiddleware` → `RequestBodyParsingMiddleware` in the recommended order behind one middleware, for consumers who don't want to manage ordering.
- [x] Constructor takes the inputs the three need — `ServerInterface $server` (error handler), `bool $debug`, `?LoggerInterface $logger`, and `string ...$supportedExtensions` (negotiation) — and wires the three internally. The building blocks remain independently constructable.
- [x] `process()` runs the composed chain by delegating to the outer error handler, which wraps the rest. Tests: an aggregate run reproduces the same outcomes as the hand-wired three-middleware chain for a happy path and each rejection path.

### Atomic-ops middleware placeholder

- [x] Reserve a documented slot in the recommended middleware order for atomic-ops dispatch (between request body parsing and the handler). Atomic Operations is a post-1.0 candidate; this is purely a documentation note so the order is stable for future work.
- [x] No code shipped this phase; just docs and a TODO comment in the recommended-order doc

### Recommended order documentation

- [x] Document the recommended middleware order in `docs/middleware-order.md` (or a similar stub for the docs phase to expand). The order is **per-server** — each `Server` instance holds its own middleware list, typically following this order:
  1. Error handler / response renderer (outermost — catches everything downstream and renders response value objects from the handler)
  2. Content negotiation
  3. Request body parsing
  4. _(Atomic ops dispatch — post-1.0 candidate. Reserved slot. When implemented, this middleware reads the atomic operations array from the request body, constructs multiple `JsonApiOperation` instances, dispatches each through the inner `OperationHandler`, and aggregates the results into an `atomic:results` response. No nested PSR-7 requests involved.)_
  5. Handler — the innermost element. Recommended path: an `OperationHandler` (Phase 1) wrapped in `Psr7ToOperationHandlerAdapter`, which translates the PSR-7 request into a `JsonApiOperation`, invokes the consumer's handler, and renders the returned response value object. Consumers who prefer PSR-15 directly can supply a `RequestHandlerInterface` instead — the error handler renders whatever response value object it returns, or passes through a PSR-7 response unchanged.
- [x] Explain why each precedes/follows another (e.g. content negotiation must run before body parsing so body parsing can reject for content-type mismatch; error handler must be outermost so it catches everything and renders return values regardless of where they came from; the atomic-ops slot sits *after* body parsing because it needs the parsed body to enumerate operations, and *before* the operation handler because it controls dispatch).
- [x] Note that the order is a recommendation, not a constraint: a server can be constructed with any middleware list it wants. The error handler being outermost is the only firm recommendation.

### Integration tests

> **Note (kick-off):** the concrete `Server` with `handle()`/`dispatch()` is Phase 4.5; only the `ServerInterface` placeholder (+ test `StubServer`) exists. The integration test therefore assembles the chain with a tiny inline PSR-15 dispatcher (a `RequestHandlerInterface` that pops middleware off a list and finally calls the innermost handler) rather than `Server::handle()`. This is the same shape the Phase 4.5 `Server` will adopt internally.

- [x] Build a small end-to-end test that wires the standard middleware chain over a `StubServer` and a tiny inline PSR-15 dispatcher, and exercises:
  - Happy path (GET with valid Accept; **inner handler is an `OperationHandler` wrapped in `Psr7ToOperationHandlerAdapter`**; the operation handler returns a `DataResponse`; the adapter renders it to PSR-7)
  - Happy path with the handler being a bare PSR-15 `RequestHandlerInterface` returning a fully-rendered PSR-7 response (passes through unchanged)
  - 415 for wrong Content-Type
  - 406 for unsupported Accept `ext` (profiles are advisory — never rejected; the rejection path is an unsupported extension)
  - 400 for malformed JSON body
  - 500 with redacted body when the handler throws unexpectedly (and verbose body when `$debug` is on)
- [x] **Multi-chain ownership test.** Construct two distinct middleware lists (e.g. one with negotiation, one without) over two `StubServer`s and confirm each chain runs independently and that selecting between them is the test's routing logic, not a middleware concern — verifying the per-server-middleware ownership pattern ahead of the Phase 4.5 `Server`.
- [x] **Programmatic dispatch sanity test.** Already covered by `tests/Operation/ProgrammaticDispatchTest.php` (an `OperationHandler` invoked directly, returning a response VO, bypassing the PSR-15 chain). Confirm it still holds; extend only if a gap is found.
- [x] Use `nyholm/psr7` and a minimal inline PSR-15 request handler / dispatcher

### Spec compliance update

- [x] Update `docs/spec-compliance.md` rows for content negotiation, error responses, and any other spec sections this phase touches

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| 2026-05-31 (kick-off) | **Middleware namespace = `haddowg\JsonApi\Middleware\`.** | Matches yin-middleware structure and the recommendation in this plan. | this phase |
| 2026-05-31 (kick-off) | **PSR-15 deps added: `psr/http-server-middleware: ^1.0`** (handler `^1.0` was already present from Phase 1). Also added **`psr/log: ^3.0`** as a runtime dep so the error handler can type-hint an optional `?LoggerInterface`. | The suite implements `MiddlewareInterface`; `psr/log` is a zero-cost interface-only package, the idiomatic home for the optional-logger hook. | this phase |
| 2026-05-31 (kick-off) | **Parsed `JsonApiRequest` propagates by swapping the request down the chain**, not via a request attribute. The first JSON:API middleware to need it wraps the incoming PSR-7 request in `JsonApiRequest` (idempotent — guarded by `instanceof JsonApiRequestInterface`) and passes that instance to `$handler->handle()`. Downstream middleware, the handler, and `Psr7ToOperationHandlerAdapter` (which already does the same `instanceof` guard) receive the already-wrapped, memoized instance and never re-parse. **No request-attribute key is introduced for the parsed request.** The existing `Target::class` routing attribute is unchanged. | Maintainer chose "swap request down the chain". `JsonApiRequest` *is* a `ServerRequestInterface`, so this is the idiomatic PSR-15 decorator pattern; it reuses `JsonApiRequest`'s per-group lazy-parse memoization across the whole chain and needs no adapter change. | this phase |
| 2026-05-31 (kick-off) | **`ErrorHandlerMiddleware` dev-mode mapping mirrors `laravel-json-api/exceptions`.** A non-`JsonApiException` `\Throwable` renders a single 500 `Error` with `title='Internal Server Error'`, `status='500'`, `code=(string)getCode()` when non-zero, and `detail=` the throwable message **only when the `$debug` flag is on** (a generic, non-leaking detail otherwise). When `$debug` is on, the throwable's `{exception: class, file, line, trace}` is attached to the **error object's `meta`** (the spec-faithful home — `source` locates the offending *request* part, and there is no standard `trace`/stack member; `meta` is free-form). Nothing leaks at the document top level. Controlled by a constructor `bool $debug = false` flag; an optional `?LoggerInterface` logs the throwable before rendering. `JsonApiException` instances render via `ErrorResponse::fromException()` unchanged (their own status/errors). | Maintainer asked how Laravel JSON:API does it; its `ExceptionParser` puts `exception/file/line/trace` in the per-error `meta` and `detail`=message under `app.debug`. This is exactly the spec-faithful mapping. | this phase |
| 2026-05-31 (kick-off) | **`RequestBodyParsingMiddleware` short-circuits when no body is present** (GET / empty body): it only forces a decode when the request actually carries a body, surfacing `RequestBodyInvalidJson` (→ 400) early. It takes **no PSR-17 factories** (it never builds a response — it throws typed exceptions the error handler renders — and it propagates the request via the swap-down-chain decision, not an attribute). **No max-body-size limit** is enforced (delegated to upstream infrastructure; documented as a recommendation). | Maintainer chose "parse only when a body is present". Forcing a decode on a bodyless GET would risk spurious 400s; `AbstractRequest::getParsedBody()` already returns `null` for an empty body. Body parsing creates no responses, so factory injection would be dead weight. | this phase |
| 2026-05-31 (kick-off) | **Ship an aggregate `JsonApiMiddleware`** that composes `ErrorHandlerMiddleware` → `ContentNegotiationMiddleware` → `RequestBodyParsingMiddleware` in the recommended order behind one `MiddlewareInterface`, for consumers who don't want to manage ordering. The three remain independently usable and independently constructable. | Maintainer chose "ship aggregate". Convenience without hiding the building blocks; the recommended-order doc still documents the canonical sequence. | this phase |
| 2026-05-31 (kick-off) | **`ContentNegotiationMiddleware` takes only `string ...$supportedExtensions`, NOT a `Server`** (`__construct(string ...$supportedExtensions)`), wrapping `Negotiation\RequestValidator(...$supportedExtensions)` (empty = reject any `ext`). This **revises acceptance criterion 4**, which said negotiation takes a `Server`. The premise no longer holds: Phase 2 made profiles **advisory** and moved **profile application into the response layer** (`AbstractResponse::toPsrResponse()`), and the supported-extension set is not on `ServerInterface`. With profiles flowing through untouched and the response layer owning Content-Type/profile/`Vary` echoing, negotiation needs no server state — only the plain supported-extension config. Holding an unused `Server` would also fail PHPStan's never-read-property check. The intent of criterion 4 (constructor injection, no request-attribute server state, no select-server middleware) is preserved. `ErrorHandlerMiddleware` still takes the `Server`. | Correction forced by Phase 2 decisions (profiles advisory; application in the response layer). Avoids a dead dependency; keeps the 415/406 `ext` path configurable and testable against the empty set today. Supported-extension config folds into the Phase 4.5 `Server` if/when it grows an extension registry. | this phase, Phase 4.5 |
| 2026-05-31 (kick-off) | **`ErrorHandlerMiddleware` does not render response VOs returned from the inner handler — only the `ErrorResponse` it builds for a caught throwable.** PSR-15 `RequestHandlerInterface::handle()` is typed `: ResponseInterface`, and the Phase-1 response VOs deliberately do not implement `ResponseInterface`, so a conforming handler can only return PSR-7 — a "bare PSR-15 handler returning a `DataResponse`" would be a `TypeError` and is not type-feasible. Consumer VOs are rendered by `Psr7ToOperationHandlerAdapter` (the recommended innermost handler), which already does `$voResponse->toPsrResponse($server, $request)`. The error handler catches `JsonApiException` (→ `ErrorResponse::fromException`) and any other `\Throwable` (→ 500), renders that `ErrorResponse`, and passes a successful PSR-7 response through untouched. **Revises acceptance criterion 5 and the integration-test "bare handler returns a VO" bullet.** | Maintainer chose "adapter renders; handler renders ErrorResponse only" when this conflict surfaced during implementation. Respects the Phase-1 VO/PSR-7 separation; avoids duplicating the adapter or bending the PSR-15 contract. | this phase |
| 2026-05-31 (kick-off) | **Negotiation has no response-side (post-handler) step.** The "ensure response Content-Type / echo profile+ext" behaviour the pre-drafted plan put on negotiation is already done by the response layer (`toPsrResponse()`) for response value objects, which the error handler renders. Since the error handler is outermost, by the time a value bubbles back to negotiation it may still be an unrendered VO — so negotiation cannot reliably post-process it. Negotiation is therefore **request-side only**. | Phase 2 moved profile/Content-Type emission into the response layer; duplicating it in negotiation would be both redundant and unreliable given middleware ordering. | this phase |

## Open questions

_All kick-off open questions resolved 2026-05-31 with the maintainer — see the decision log rows above._

- ~~Single `JsonApiMiddleware` aggregate?~~ **Resolved: ship the aggregate** (composes the three in recommended order); the building blocks stay independently usable.
- ~~Where do PSR-17 factories come from in middleware?~~ **Resolved:** `ErrorHandlerMiddleware` reads them from the `Server` (already exposes `responseFactory()`/`streamFactory()`); body parsing needs none. No combined factory object.
- ~~`meta.exception` in dev mode vs yin-style top-level?~~ **Resolved:** per-error `meta` `{exception,file,line,trace}` + `detail`=message, mirroring `laravel-json-api/exceptions`; gated by a `$debug` flag. Nothing at document top level.
- ~~Body-parsing max-size limit?~~ **Resolved: no limit in core**; delegated to upstream infrastructure, documented as a recommendation.
- ~~Body parsing short-circuit for bodyless methods?~~ **Resolved: parse only when a body is present** (skip GET/empty body).

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. The three middleware (`ContentNegotiationMiddleware`, `RequestBodyParsingMiddleware`, `ErrorHandlerMiddleware`) exist under the agreed namespace.
3. Each middleware has unit tests covering its happy path and each spec-mandated rejection path.
4. `ErrorHandlerMiddleware` takes a `Server` in its constructor; `ContentNegotiationMiddleware` takes its supported-`ext` config as a constructor variadic (revised at kick-off — see decision log: negotiation needs no server state now that profiles are advisory and applied in the response layer). No middleware reads server state from a request attribute. No `SingleServerMiddleware` or select-server middleware ships in core.
5. `ErrorHandlerMiddleware` renders the `ErrorResponse` it builds for any caught throwable (`JsonApiException` → its own status/errors; other `\Throwable` → 500), and passes a successful PSR-7 response from the inner handler through unchanged. Consumer response VOs are rendered by `Psr7ToOperationHandlerAdapter` (the recommended innermost handler), not by the error handler — PSR-15's `: ResponseInterface` contract means a conforming handler cannot return a VO (revised at kick-off; see decision log). Unit + integration tests cover the caught-throwable and pass-through paths plus the adapter-renders-VO path.
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
