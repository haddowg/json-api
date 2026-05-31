# Phase 4 — Validation

## Goal & scope

Add optional, dev-time JSON Schema validation of JSON:API request and response bodies, backed by `opis/json-schema` and the official JSON:API JSON Schema. The aim is to catch malformed JSON:API documents early during development, not to be a production hot path. Validation is exposed as middleware so consumers can drop it into the chain established in Phase 3.

**In scope:**

- Optional dependency on `opis/json-schema`
- Acquisition and storage of the JSON:API 1.1 base JSON Schema
- Request body validator (validates parsed JSON against the schema) and response body validator (validates outgoing documents)
- Two PSR-15 middleware wrapping the validators
- A hook for profiles to contribute schema fragments that augment validation
- Tests covering each validator against well-formed and malformed fixtures, including profile-augmented validation

**Out of scope:**

- Atomic Operations extension validation (post-1.0 candidate; will reuse this phase's schema-fragment hook design when it's scheduled)
- **Per-resource-type schema compilation from field constraints.** Deferred to Phase 4.5 (Fluent Schema DSL), which introduces the schemas + constraint metadata this would compile from. The validator architecture here must be extensible to accept a per-resource-type compiled schema as input; the compilation itself ships in Phase 4.5.
- Making validation a runtime default — it stays opt-in and recommended for dev/CI use only
- Replacing the typed exception layer; validation produces typed exceptions that flow through the existing error handler

## Prerequisites

- Phase 3 complete: PSR-15 middleware suite in place, recommended order documented
- Phase 2 profile infrastructure exists with a registry hook for profile-provided contributions
- Phase 1 typed exception hierarchy is the surface for validation failures

## Kick-off

Before writing any implementation code:

1. Read `docs/phase-3-middleware.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. Decide how the JSON:API JSON Schema is acquired. Options to evaluate:
   - **Vendored** (committed to the repo under a path like `resources/schemas/jsonapi-1.1.json`). Pros: reproducible, no runtime dep on jsonapi.org. Cons: must be refreshed if the schema changes.
   - **Fetched** at runtime (with caching). Pros: always current. Cons: network dependency, harder to test deterministically.
   - **Pulled from an upstream Composer package** if one exists and is well-maintained.
   - Recommendation: vendor, document the source URL and refresh procedure.
   - Survey official/community JSON:API JSON Schema sources during kick-off; record findings in the decision log.
3. Confirm `opis/json-schema` major version and any sub-dependencies; add to `require-dev` initially (later promoted to `suggest` so production consumers don't pay the cost).
4. Re-read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
5. Revise the task list as needed and commit the plan revision as a single commit before starting implementation.

## Task list

### Schema acquisition

- [ ] Resolve the schema source per kick-off step 2 and capture the chosen approach in the decision log
- [ ] If vendoring: place the schema file under `resources/schemas/` (or chosen path), and document the upstream source + how to refresh
- [ ] Add a `haddowg\JsonApi\Validation\SchemaProvider` interface and at least one implementation that returns the base JSON:API schema as a parsed structure suitable for `opis/json-schema`
- [ ] Unit tests for the provider (schema parses, content matches what we expect)

### Validator core

- [ ] `haddowg\JsonApi\Validation\DocumentValidator` (or split into `RequestDocumentValidator` / `ResponseDocumentValidator` if their behaviour diverges meaningfully — decide during implementation)
- [ ] Validator accepts:
  - The schema (from the provider) — at this phase, the JSON:API 1.1 base schema possibly augmented by profile fragments
  - The decoded JSON document (array/object)
  - The profile registry (so profile-provided schema fragments can augment validation)
- [ ] **Design the validator interface so that an additional per-resource-type schema can be supplied as a third input** (in addition to the base schema and profile fragments). Phase 4.5 will compile per-resource schemas from field constraints and pass them through this entry point. Concretely: the validator should accept a list/composition of schemas to validate against, not a single schema. The base schema is always present; profile fragments and per-resource schemas are optional inputs that compose.
- [ ] Validation output:
  - Success: nothing returned, no exception
  - Failure: throws a typed exception (`haddowg\JsonApi\Exception\DocumentValidationFailed` or similar) carrying the list of `opis/json-schema` violations, mapped into JSON:API-shaped errors (source pointers populated from the JSON Pointer reported by opis)
- [ ] The thrown exception implements `JsonApiException` — its `getErrors(): list<Error>` returns one `Error` per violation (including `source.pointer` per spec) and `getStatusCode()` returns `400`; the error-handler middleware renders these into a JSON:API error document

### Profile schema-fragment hook

- [ ] Extend `ProfileInterface` (or add a sibling interface like `SchemaContributingProfile`) with a method that returns an optional JSON Schema fragment describing the profile's reserved keywords / extensions
- [ ] On validation, the validator collects fragments from all profiles in scope for the current request/response, merges them with the base schema (semantics: profile fragments extend `properties` / `patternProperties` of the relevant schema objects — the exact merge strategy needs to be designed during implementation)
- [ ] Decide: are profile fragments validated themselves at registration time? Lean: yes, fail fast at startup.
- [ ] Tests: profile contributes fragment → augmented validation accepts profile-defined keyword that base schema would reject; profile with no fragment → base validation unchanged

### Request validation middleware

- [ ] `haddowg\JsonApi\Middleware\RequestValidationMiddleware`
- [ ] **Constructor takes a `Server`** (or Phase 1 placeholder) — provides the profile registry for fragment merging and (post-Phase-4.5) the per-resource schemas. Same per-server-ownership pattern as the other Phase 3 middleware.
- [ ] Behaviour:
  - Runs after `RequestBodyParsingMiddleware` (so the request reaching it is already the parsed `JsonApiRequest` swapped down the chain — Phase 3 uses no request attribute; read the body via `getParsedBody()`)
  - Skips if there's no JSON body (GET, DELETE without body)
  - Validates the parsed body against the (profile-augmented) JSON:API request schema
  - On failure, throws the validator's typed exception → caught by `ErrorHandlerMiddleware` from Phase 3
- [ ] Configurable: enabled/disabled flag (consumers add it conditionally based on environment); optional severity (warn-only mode that logs but doesn't reject — decide if useful)
- [ ] Tests: well-formed body passes; missing required member rejected; invalid type rejected; profile-augmented body passes
- [ ] **Per-server opt-in.** This middleware is added to the server's middleware list at server construction time. Different servers in the same app can have different validation policies (e.g. v1 doesn't run validation, v2 does).

### Response validation middleware

- [ ] `haddowg\JsonApi\Middleware\ResponseValidationMiddleware`
- [ ] Constructor takes a `Server` (or placeholder) plus optional PSR-3 logger.
- [ ] Behaviour:
  - Runs after the handler returns (post-handler middleware)
  - Reads the response body, decodes, validates against the (profile-augmented) JSON:API response schema
  - On failure: this is a server bug, not a client bug. Decide failure mode:
    - Throw → 500 via error handler (loud, ensures dev notices)
    - Log only → useful for production-soak but defeats the point in dev
    - Both, controlled by a flag
  - Lean: throw by default; flag to downgrade to logging
- [ ] Tests: well-formed outgoing document passes; deliberately broken document fails; broken with logger-only mode logs and passes through

### Middleware ordering update

- [ ] Update `docs/middleware-order.md` (from Phase 3) to slot the new validators:
  1. Error handler
  2. Content negotiation
  3. Request body parsing
  4. **Request validation** _(this phase, optional, dev/CI)_
  5. _(Atomic ops dispatch — post-1.0 candidate)_
  6. Handler
  7. **Response validation** _(this phase, optional, dev/CI, runs as the response unwinds)_

### Composer wiring

- [ ] `composer.json`:
  - Add `opis/json-schema` to `suggest` with a useful message
  - Keep it in `require-dev` so the package's own tests can run
  - Confirm the validators degrade gracefully if `opis/json-schema` is absent at runtime (likely: middleware constructors require an `opis` validator instance, so DI in the consumer's container fails fast if it's not installed — that's acceptable and explicit)

### Spec compliance update

- [ ] Update `docs/spec-compliance.md` for `spec:document-structure` — many of its MUSTs become test-asserted via schema validation
- [ ] Add a note in `docs/spec-compliance.md` explaining the validation-as-test-aid relationship (running the dev-mode validators against the test suite would meaningfully tighten spec assertions; consider a dedicated CI job)

### Optional: validation as a CI quality gate

- [ ] _(Stretch)_ Add a CI job that runs the test suite with response validation forced on, to catch any test fixtures or generated documents that don't conform. If it surfaces too many issues, defer to a follow-up. Record outcome in the decision log.

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| _yyyy-mm-dd_ | _(example: vendored schema at `resources/schemas/jsonapi-1.1.json`, sourced from https://...)_ | _(rationale)_ | _(this phase / future phases)_ |

## Open questions

- Is there a maintained official JSON:API 1.1 JSON Schema? If not, the closest community schema becomes the canonical source — confirm during kick-off.
- Merge semantics for profile schema fragments: JSON Schema `allOf`, custom merge of `properties`, something else? Need a concrete strategy that's intuitive for profile authors and correct under JSON Schema semantics.
- Should request and response validation use the same schema, or separate schemas (request bodies have different required members than responses)? Almost certainly separate; confirm during implementation.
- For atomic operations payloads (post-1.0 candidate), the request shape is fundamentally different (`atomic:operations` instead of `data`). When that work is scheduled, the validator can either detect the atomic content-type and switch schemas, or atomic ops can ship its own middleware that bypasses the base validator. Lean: separate concerns, atomic ops middleware contributes its own validator. No action needed in this phase.
- Should `DocumentValidationFailed` produce one HTTP `400` (with multiple errors in the document) or one error per violation? Spec allows multiple errors; ship multiple-errors-in-one-response.
- Performance: should the schema be compiled once and reused across requests? `opis/json-schema` supports this; confirm and use compiled form.

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. The base JSON:API JSON Schema is available to the package via the resolved acquisition mechanism.
3. `DocumentValidator` validates a hand-rolled malformed request body and produces a JSON:API error document with correct `source.pointer` values for the violation(s).
4. `RequestValidationMiddleware` and `ResponseValidationMiddleware` exist, are documented in the recommended order, and have unit + integration tests.
5. A test exercises a profile contributing a schema fragment and the validator accepting a profile-defined keyword that base validation would reject.
6. PHPStan level 9 passes; CI matrix green; spec-tagged tests pass.
7. `composer.json` lists `opis/json-schema` as a `suggest`, not a `require`.
8. `docs/spec-compliance.md` updated; `docs/middleware-order.md` updated.
9. `CLAUDE.md` updated with pattern entries for validators (schema-provider wiring, profile-fragment merge, violation → JSON:API error mapping) and any refinements to the middleware pattern from Phase 3.

### Verification plan

```bash
composer install
composer test
composer phpstan
composer cs-check

# Validation-specific spec coverage
vendor/bin/phpunit --group spec:document-structure
vendor/bin/phpunit --group spec:errors

# Lowest-deps run
composer update --prefer-lowest --prefer-stable
composer test

# Production-install sanity: opis must not be required to install the package
composer create-project --no-dev --repository='{"type":"path","url":"."}' \
  haddowg/json-api /tmp/jsonapi-prod-sanity
# Verify the package installs and core types load without opis
php -r 'require "/tmp/jsonapi-prod-sanity/vendor/autoload.php"; \
  echo class_exists("haddowg\\JsonApi\\Server\\Server") ? "OK" : "FAIL";'
```

Manual review:

- Hand-craft a JSON:API document missing a required top-level member (`data`/`errors`/`meta`); run it through `DocumentValidator`; confirm the error document points at the right location.
- Register a throwaway profile that declares a keyword and contributes a schema fragment allowing it; send a request whose body uses the keyword; confirm validation passes when the profile is in scope and fails when it isn't.
- Confirm response validation catches a deliberately-broken outgoing document fixture.

## Handover output

Before declaring the phase complete, produce the following for Phase 4.5:

1. **Status table update** in `docs/PLAN.md` — Phase 4 → `Complete`, Phase 4.5 → `Ready`.
2. **Phase 4.5 plan review** — `docs/phase-4-5-fluent-schema.md` already exists as a pre-drafted plan. Read it end-to-end against this phase's decision log and current repository state. Confirm it still covers at minimum:
   - The `Field`, `Constraint`, `Filter`, `Sort`, `Schema`, `Server` contracts and their concrete implementations
   - The split field-type inventory (`Str`, `Email`, `Url`, `Uuid`, `Slug`, `Ip`, `Integer`, `Decimal`, `Boolean`, `Date`, `DateTime`, `Time`, `ArrayList`, `ArrayHash`, `Map`, `Id`)
   - The constraint vocabulary and its create/update context model
   - The schema's default implementation of yin's `Resource` (serializer) and `Hydrator` contracts ported in Phase 1
   - The `Server` (per-API-version configuration root) resolving custom resource/hydrator overrides ahead of the schema fallback, holding the middleware list, and implementing `RequestHandlerInterface`
   - The JSON Schema compiler (`SchemaCompiler`) that consumes constraint metadata and feeds the validator entry point established in this phase
   - The filter/sort handler pattern (no generic `Query` interface in core; adapters ship handlers)
   - That the Phase 1 `Server` placeholder is expanded into the full `Server` here without breaking the rendering signatures on the response value objects
   - Append revisions to the plan as a single commit; the actual kick-off revision happens at the start of Phase 4.5, but corrections forced by Phase 4 decisions belong here.
3. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 4.5.
4. **Decision log finalised** — phase-local decisions captured here; any cross-phase decisions promoted to `PLAN.md`.

### Note on deferred work

The schema-fragment hook introduced in this phase was originally designed with a future Atomic Operations consumer in mind. Atomic Operations is now a post-1.0 candidate, so the hook does **not** need to be generalised for `ext` use during this phase — keep it profile-specific. When Atomic Operations work begins, the hook can be generalised or extended at that time.

Per-resource schema compilation from field constraints is deferred to Phase 4.5. The validator architecture in this phase accepts an optional per-resource schema as a third input alongside the base schema and profile fragments; the compiler that produces those per-resource schemas ships in Phase 4.5.

## Phase 3 reconciliation notes (appended at Phase 3 close)

These corrections are forced by Phase 3 decisions; fold them into the plan at the
Phase 4 kick-off revision (see `docs/phase-3-middleware.md` decision log).

- **The parsed request flows by being swapped down the chain, not via a request
  attribute.** `RequestBodyParsingMiddleware` wraps the PSR-7 request in a
  `Request\JsonApiRequest` (which *is* a `ServerRequestInterface`) and passes that
  instance to `$handler->handle()`. The validation middleware therefore receives
  the already-parsed `JsonApiRequest` directly and reads the body via
  `getParsedBody()` / the `getResource*` accessors — there is no request-attribute
  key to read. The only routing attribute in play is `Operation\Target::class`.
- **Validation failures need no new rendering path.** `ErrorHandlerMiddleware`
  (Phase 3, outermost) already catches any `JsonApiException` and renders it via
  `ErrorResponse::fromException()`. `DocumentValidationFailed` must implement
  `JsonApiException` (`getErrors()` → one `Error` per violation with
  `source.pointer`; `getStatusCode()` → `400`) and it renders for free.
- **Per-server middleware ownership is established** (`docs/middleware-order.md`).
  `RequestValidationMiddleware`/`ResponseValidationMiddleware` slot in after body
  parsing / as the response unwinds, exactly as the order doc reserves. They take a
  `Server` (for the profile registry, and post-4.5 the per-resource schemas) —
  consistent with `ErrorHandlerMiddleware`. (Note: `ContentNegotiationMiddleware`
  ended up *not* taking a `Server` because negotiation needs no server state; the
  validators genuinely do, so they keep the `Server` constructor.)
- **The recommended-order doc already exists** at `docs/middleware-order.md` (not a
  stub to create). Phase 4 *updates* it to insert the two validators rather than
  authoring it from scratch.
- **`ResponseValidationMiddleware` runs as the response unwinds**, but note the
  error handler is outermost and the operation adapter renders consumer VOs to
  PSR-7 *below* it — so by the time the response bubbles up to a response-validation
  middleware placed just inside the error handler, it is already a rendered PSR-7
  document the validator can decode. Place response validation inside the error
  handler (so a validation throw is caught) but outside negotiation/body-parsing.
