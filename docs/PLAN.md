# haddowg/json-api — Master Plan

## Overview

`haddowg/json-api` is a modernised, server-side JSON:API 1.1 library for PHP. It begins as a fork of [woohoolabs/yin](https://github.com/woohoolabs/yin) (effectively abandoned) and evolves into a contemporary library that takes advantage of PHP 8.3+ language features and formalises JSON:API profiles. The Atomic Operations extension and attribute-driven resource definitions are planned as post-1.0 enhancements.

**Package**: `haddowg/json-api`
**Namespace**: `haddowg\JsonApi\…`
**Minimum PHP**: 8.3
**Initial version**: `0.1.0`; commits to semver at `1.0.0`
**Audience**: Public release; no backward-compatibility constraint with woohoolabs/yin

## Goals

1. Modernise the codebase for PHP 8.3+ idioms (readonly, enums, typed properties, constructor promotion, etc.)
2. Reach and verifiably maintain 100% JSON:API 1.1 specification compliance
3. Provide first-class server-side support for JSON:API profiles
4. Ship a PSR-15 middleware suite for the standard JSON:API request lifecycle
5. Provide a stable, well-tested foundation suitable for production use

### Post-1.0 goals

- Atomic Operations extension support (server-side)
- Attribute-driven resources and hydrators to reduce boilerplate

## Non-goals

- Client-side JSON:API support (the original `woohoolabs/yang` project covered this; out of scope here)
- Backward compatibility with woohoolabs/yin's public API
- Framework integrations (Symfony bundle, Laravel package, etc.) — consumers wire it themselves
- Migration tooling (Rector rules) and standalone migration guide — deferred as future enhancements

## High-level decisions

| Area | Decision |
|---|---|
| Namespace structure | The fluent type introduced in Phase 4.5 took the name **`Resource\*`** (not `Schema`): `Schema\Resource\*` (yin's per-resource-type serializer) was renamed to top-level **`Serializer\*`** (`SerializerInterface`/`AbstractSerializer`), freeing `Resource` for the encapsulating fluent schema (`Resource\AbstractResource` + `Resource\Field\*`/`Constraint\*`/`Filter\*`/`Sort\*`). The remaining document-part subnamespaces stay under `Schema\*` (`Schema\Document\*`, `Schema\Link\*`, `Schema\Error\*`, `Schema\Relationship\*`, `Schema\Profile\*`, `Schema\JsonApiObject`, `Schema\ResourceIdentifier`) — the originally-planned `Schema\*`→`Document\*` rename was dropped (it collided as `Document\Document` and the `Resource`/`Serializer` split made it unnecessary). |
| PSR-7 | `psr/http-message` v2 interfaces only in core; `nyholm/psr7` as dev dependency |
| Response model | Documents (yin's `AbstractDocument` and subclasses) become **internal** types in Phase 1. Public response surface is a small set of immutable response value objects — `DataResponse`, `MetaResponse`, `RelatedResponse`, `IdentifierResponse`, `ErrorResponse` — each with fluent `withMeta` / `withLinks` / `withJsonApi` / `withHeader` / `withEncodeOptions` chaining. Consumers never write a `Document` subclass; per-resource-type serialization concerns live on the schema, per-response-shape concerns are the response value object's. Matches Laravel JSON:API's response-class pattern. |
| Handler model | Handlers are decoupled from PSR-7. Phase 1 ships `JsonApiOperation` (a value-object family, one per JSON:API verb, carrying the parsed target / query params / body / context) and an `OperationHandler` interface (`handle(JsonApiOperation): DataResponse|...`). A `Psr7ToOperationHandlerAdapter` bridges PSR-15 and `OperationHandler`. PSR-15 handlers remain supported as an escape hatch. Programmatic dispatch via `$server->dispatch($operation)` bypasses the PSR-15 chain — useful for integration tests, internal calls, and the eventual post-1.0 atomic-ops dispatcher (which constructs multiple operations from one HTTP request and dispatches each through the same `OperationHandler`, no synthetic PSR-7 requests). |
| Middleware | PSR-15 adapters shipped in 1.0: content negotiation, request body parsing, error handling (which also renders response value objects), and (optional) request/response schema validation. Atomic ops dispatch reserved as a post-1.0 slot. **Middleware is owned by a `Server`** — each server holds its own ordered middleware list and is itself a `RequestHandlerInterface`. Framework routing dispatches to the appropriate server; the server runs its chain. No global middleware registry; no select-server middleware in core. |
| Serialization | Drop `SerializerInterface`; inline `json_encode` with `JSON_THROW_ON_ERROR` |
| Error handling | Typed exception hierarchy implementing a common `JsonApiException` contract; replaces `ExceptionFactory` |
| Resource definition | **Three-layer public surface at 1.0.** The fluent `Schema` (Phase 4.5) is the recommended primary surface and implements yin's `Resource` (per-resource-type serializer) and `Hydrator` (per-resource-type deserializer) contracts by default. `Resource` and `Hydrator` (Phase 1) remain first-class public API as documented escape hatches for custom serialization / hydration. Consumers can register a schema, a resource override, a hydrator override, or any combination; or skip the schema entirely and register a resource + hydrator pair directly. (Note: yin's `Resource` class is the serializer; it is *not* the JSON:API spec's "resource object" — that is `ResourceObject` in the document namespace.) |
| Resources/Hydrators | Class-based first (Phase 1); fluent schema layer on top (Phase 4.5). Attribute-driven layer is a post-1.0 candidate. |
| Pagination | Replace yin's `PaginationLinkProviderInterface` + trait-on-collection pattern (not ported). Phase 2 ships `Paginator` (the strategy: reads `page[...]` params, has fluent config) and `Page` (the value object: strategy-specific subclass per pagination kind, carries paginated items + strategy metadata, knows how to emit `links.{first,prev,next,last}` and `meta.page.{...}`). Collections stay collections; pagination concerns never leak into them. `DataResponse::make($page)` is the paginated case. |
| Profiles | Full general-purpose infrastructure; paginators are first consumers. Phase 2's profile registry folds into the broader `Server` value object in Phase 4.5. |
| Versioning | Multiple `Server` instances supported; each has its own schemas, profiles, base URI, version metadata, paginator defaults, and middleware list. Core ships the capability; framework routing layers (Symfony bundle, consumer-side routers) wire URL prefix → server. No versioning-specific tooling in core. |
| Validation | `opis/json-schema` against the JSON:API base schema (Phase 4); per-resource JSON Schema compiled from field constraint metadata (Phase 4.5). Core ships constraint metadata only; framework adapters run validators. |
| Field constraint metadata | Structured readonly value objects implementing a `Constraint` contract. Each constraint carries a `Context` (create/update). `Required` defaults to "must be non-empty if present" on PATCH, "must be present and non-empty" on POST. |
| Atomic Operations | Post-1.0 candidate. Phase 2 leaves an `ext` parsing hook in negotiation; the dispatcher itself is deferred |
| Spec compliance | Verified progressively during implementation; tests tagged by spec section |
| Type system | **Default to PHPStan generics on consumer-visible types that carry a parametric payload.** Common cases: `Page<T>`, `DataResponse<T>`, `Field<T>`, `Each<T>`, `In<T>`, `OperationHandler<TOperation>`, and `Server` / lookup-method `class-string<T>` → narrowed return. Skip generics on internal types where consumers don't see them, on PSR-* boundary types where impedance with framework code outweighs narrowing, and where `instanceof` / `match` already narrows just as well. Apply at port time alongside the type being introduced, not as a retroactive sweep. Verify with cherry-picked rules from `phpstan-strict-rules` (generics-specific subset only — `noVariableVariables` and similar non-generic rules stay opt-in per consumer). |

## Phase index

Phase plans are pre-drafted in `docs/` and intended to be revised at each phase's kick-off using the prior phase's decision log as input. Each phase is independently shippable as a `0.x` release.

| # | Phase | Plan | Status |
|---|---|---|---|
| 0 | Repo bootstrap | [phase-0-bootstrap.md](./phase-0-bootstrap.md) | Complete |
| 1 | Core port & modernise | [phase-1-core-port.md](./phase-1-core-port.md) | Ready |
| 2 | Profiles + pagination | [phase-2-profiles-pagination.md](./phase-2-profiles-pagination.md) | Complete |
| 3 | PSR-15 middleware suite | [phase-3-middleware.md](./phase-3-middleware.md) | Complete |
| 4 | Validation | [phase-4-validation.md](./phase-4-validation.md) | Complete |
| 4.5 | Fluent schema DSL | [phase-4-5-fluent-schema.md](./phase-4-5-fluent-schema.md) | Complete |
| 5 | Docs port & update | [phase-5-docs.md](./phase-5-docs.md) | Ready |
| 6 | 1.0 readiness review | [phase-6-readiness-review.md](./phase-6-readiness-review.md) | Not started |

### Post-1.0 candidate phases

These are out of scope for the initial 1.0 release. Order is not fixed; they are independent of each other and will be scheduled based on demand after 1.0 ships. A plan for each is generated when work is scheduled.

| Candidate | Description |
|---|---|
| Atomic Operations extension | Spec primitives + opinionated dispatcher with transaction hooks; consumes the `ext` parsing hook left in Phase 2 and the `JsonApiOperation` / `OperationHandler` abstraction shipped in Phase 1. The dispatcher constructs multiple operations from one HTTP request and invokes each via `$server->dispatch($operation)`, so no consumer-side handler changes are required when atomic ops is scheduled. |
| Attribute-driven resources/hydrators | `#[ResourceType]`, `#[Attribute]`, `#[Relationship]`, `#[Profile]`; reduces boilerplate over the Phase 1 class-based API |
| OpenAPI spec generation | Walks a `Server`'s registered schemas, profiles, and operation handlers to emit an OpenAPI 3.x document. Per-resource component schemas derived from `Field` and `Constraint` metadata (same source as the JSON Schema compiler); paths from JSON:API URL conventions × the per-verb operation classes; request/response envelopes from the spec. **No 1.0 design dependency** — the constraint vocabulary, schema layer, and verb representation already in 1.0 carry the metadata an OpenAPI generator needs. Distribution undecided: separate package (`haddowg/json-api-openapi`) vs. `bin/jsonapi-openapi` command in core. Decide when scheduled. |

Other candidates may emerge from the 1.0 review.

## Phase plan conventions

Every phase plan document follows this structure:

1. **Goal & scope** — what the phase delivers and what is explicitly out of scope
2. **Prerequisites** — links to prior phase handover docs and any required external state
3. **Kick-off** — required first actions when starting the phase: review the prior phase's handover output and decision log, reconcile with the current repository state, and revise this plan (resolve open questions, add/remove tasks, update task descriptions) **before** writing any implementation code. Any unresolved open questions — both those already listed and any new ones surfaced during the review — must be resolved by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess answers and do not silently defer. Record each answer in the decision log. The plan revision must be committed as its own commit.
4. **Task list** — granular checkboxes, sized for incremental commits
5. **Decision log** — decisions made during execution that affect this or future phases; appended to as work progresses. Decisions that materially affect later phases or the package's overall direction are promoted to the cross-phase log in this master plan at phase close (this is part of every phase's handover output).
6. **Open questions** — items to resolve mid-phase
7. **Acceptance criteria** — definition of done, **including a verification plan** (concrete steps to prove the phase is complete, not just checklist completion)
8. **Handover output** — specification of what state, artefacts, and decisions must exist for the next phase to begin. Any open questions that remain unresolved at handover (including new ones surfaced during the phase) must be resolved by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to the next phase silently.

Each plan must be self-contained enough that a fresh Claude Code session can resume work using only the plan and the repository state. Append decisions to the phase's decision log as you go.

## Executor playbook (`CLAUDE.md`)

A `CLAUDE.md` file lives at the repository root and serves as the executor-facing playbook for modernisation patterns and operational practices. It is not consumer documentation; it is the source-of-truth for how the codebase is written and how work is executed, so that any Claude Code session — whether a continuation, a restart, or a context-compacted resumption — produces consistent output.

`CLAUDE.md` is initialised in Phase 1 with patterns for each kind of component being ported (value objects, document classes, resources, hydrators, exceptions, enums, paginators, etc.) and with the operational rules below. Every subsequent phase that introduces a new component kind (middleware in Phase 3, validators in Phase 4, etc.) adds a corresponding pattern entry. Every phase's acceptance criteria include "`CLAUDE.md` pattern entries for any new component kinds introduced are present and accurate."

Pattern entries are kept short — a paragraph plus a minimal code sketch. If an entry grows long, the underlying abstraction probably needs work.

### Required operational rules in `CLAUDE.md`

The following rules must be present in `CLAUDE.md` from its creation and apply to all phases:

- **Single-threaded until pattern established.** When porting or building the first instance of a component kind, work sequentially in the main worktree. The first instance teaches the pattern; the pattern entry in `CLAUDE.md` is written or refined as that instance is completed.
- **Batching is eligible only when:** (a) the pattern entry for the component kind exists in `CLAUDE.md`, (b) at least one full instance has been ported, tested, and merged, and (c) the remaining work is mechanical application of the established pattern.
- **Parallel work uses git worktrees.** When fanning out work to subagents, each subagent operates in its own worktree (`git worktree add ../worktrees/<task-id> <branch>`). Subagents do not share a working directory. This prevents file-level conflicts and lets each subagent run its own tests in isolation.
- **One component kind per fan-out.** A single batch parallelises work on instances of the same component kind (e.g. multiple exception classes, multiple resources). Do not mix kinds in one batch; the patterns and review needs differ.
- **Convergence is sequential.** Subagent branches are merged back into the working branch one at a time, in a deterministic order, with CI green at each merge. Do not merge in parallel.
- **Refinements halt the batch.** If a subagent surfaces a pattern refinement that should propagate to others in the batch, pause the remaining subagents, update `CLAUDE.md`, then resume. Do not let the batch produce inconsistent output.
- **Consolidation review after every fan-out.** Once all subagent branches in a batch are merged, run a consolidation review **before** declaring the batch complete or starting another batch. The review:
  1. Reads every file produced by the batch against the pattern entry in `CLAUDE.md`.
  2. Identifies variations and classifies each as (a) accidental drift to be corrected, (b) a legitimate exception to be documented in the pattern entry as a recognised variant, or (c) evidence that the pattern itself should change (in which case it's a refinement, and applies retroactively to the just-merged batch).
  3. Produces a single consolidation commit (or a small focused set) that aligns drift, documents exceptions in `CLAUDE.md`, and applies any retroactive refinements.
  4. Records the review outcome — variations found, classification, and any pattern updates — in the phase's decision log.

  No further batches of the same component kind may start until consolidation is complete.
- **Tests port file-by-file alongside their implementations.** When porting a source file from yin (or building a new component that has any existing test coverage to inherit), the corresponding test file is ported, modernised, and brought green in the same commit (or an adjacent commit on the same branch). Tests are not deferred to a bulk end-of-phase pass; deferring them defers integration risk and lets API drift accumulate undetected. The rule applies in fan-outs too: each subagent ports an implementation plus its tests together, not just the implementation.
- **Worktree cleanup.** Worktrees are removed (`git worktree remove`) after their branch is merged or abandoned.

## Cross-phase decision log

Decisions captured here apply across phases. Phase-local decisions live in each phase plan.

| Date | Decision | Rationale |
|---|---|---|
| 2026-05-16 | Forking woohoolabs/yin under `haddowg/json-api` | yin is effectively abandoned; want modern language features and reduced boilerplate |
| 2026-05-16 | Target PHP 8.3+; bump only on majors | Balances modern features with reasonable support window |
| 2026-05-16 | Server-side only, like yin | The package's focus and shape; client-side is a separate concern |
| 2026-05-16 | Drop `SerializerInterface` | Existing implementation is a trivial `json_encode` wrapper; abstraction not earning its keep |
| 2026-05-16 | Typed exceptions replace `ExceptionFactory` | More idiomatic for modern PHP; consumers customise via middleware/catch-rethrow |
| 2026-05-16 | Profiles get general-purpose infrastructure, not just paginator-coupled | Sets up Atomic Ops + future attribute-driven profile declarations |
| 2026-05-16 | Atomic Ops dispatcher exposes transaction hooks | Spec mandates "MUST NOT apply any" on failure; pure hands-off makes non-compliance too easy |
| 2026-05-16 | Tests tagged with PHPUnit groups by JSON:API spec section | Spec traceability without a duplicate test layer |
| 2026-05-16 | Infection deferred | Useful but not blocking; revisit post-1.0 |
| 2026-05-16 | Migration guide + Rector rules deferred | Out of scope for initial 0.x; revisit if community demand exists |
| 2026-05-16 | Atomic Operations and attribute-driven hydrators moved to post-1.0 candidates | Reduces scope for 1.0; both are independent enhancements that benefit from real consumer feedback on the core before being designed |
| 2026-05-16 | MIT licence with dual copyright (haddowg + original yin authors); README acknowledges woohoolabs/yin | Substantial portions of the codebase derive from yin (also MIT); preserving original attribution is both a licence obligation and a deliberate acknowledgement of the upstream work |
| 2026-05-16 | Schema layer (Phase 4.5) ships pre-1.0 as the recommended primary surface | Laravel JSON:API's fluent schema DSL is materially better DX than yin's resource/hydrator pair. Adding it pre-1.0 means 1.0 docs lead with the better API rather than ageing on day one. Schema implements yin's `Resource` (serializer) and `Hydrator` contracts by default; custom resources and hydrators remain first-class escape hatches. |
| 2026-05-16 | Three-layer public API at 1.0: schema → resource → hydrator, each independently usable | Schema is the 95% case; resource and hydrator overrides handle the long tail. Skipping the schema entirely and using a bare resource + hydrator pair remains supported. No deprecation needed for either layer. "Resource" here refers to yin's per-resource-type serializer class (`AbstractResource` / `ResourceInterface`), not the JSON:API spec's "resource object." |
| 2026-05-16 | Constraint metadata is structured value objects implementing a `Constraint` contract, not free-form strings | Laravel's `'required\|string\|max:200'` DSL works in a Laravel-only world. A framework-agnostic core needs typed metadata so adapters (Symfony Validator, future Laravel adapter) can translate without inventing a parser; static analysis and IDE completion are free wins. |
| 2026-05-16 | `Required` semantics: "must be non-empty if present"; absence on PATCH is acceptable (partial update); `requiredOnCreate` / `requiredOnUpdate` are the stricter forms | Matches JSON:API PATCH semantics directly. Diverges from Laravel's stricter default but aligns with the spec, which is the right baseline for a JSON:API-specific package. |
| 2026-05-16 | Core ships constraint metadata only; never executes validators against data | The JSON Schema compiler (Phase 4.5) consumes the metadata for structural validation; framework adapters consume the same metadata for full request validation. Separating metadata from execution is what makes adapters small. |
| 2026-05-16 | Filter and sort execution split from definition via adapter-provided handlers; no generic `Query` interface in core | Mirrors the `Constraint` + constraint-translator pattern: core ships typed metadata, adapters ship handlers that translate the metadata into their data layer's native operations. Avoids the design risk of a generic `Query` interface (too narrow or too relational); lets adapters use their native query builders (Doctrine `QueryBuilder`, etc.) directly. Trade-off: a custom filter without a registered handler is inert, same trade-off as `Constraint::Custom`. Reference array-backed handlers ship in core for tests and worked examples. |
| 2026-05-16 | Documents become internal types; public response surface is response value objects (`DataResponse`, `MetaResponse`, `RelatedResponse`, `IdentifierResponse`, `ErrorResponse`) | Yin's pattern of subclassing `AbstractDocument` per response shape (`BookDocument`, `BooksDocument`, `BookErrorDocument`, ...) produces large amounts of boilerplate where every subclass re-declares `getJsonApi`/`getMeta`/`getLinks`. With schemas in play, per-resource-type serialization concerns belong on the schema; per-response-shape concerns belong on a response value object. Consumers construct `DataResponse::make($model)->withMeta([...])` and the framework figures out which document machinery to use internally. Matches Laravel JSON:API's response-class pattern. Yin's `Responder` class shrinks to internal use or disappears. |
| 2026-05-16 | `Server` is the per-API-version configuration root and is a PSR-15 `RequestHandlerInterface` | Replaces the `Registry` sketch from earlier drafts. A `Server` holds the schema registry, profile registry, base URI, JSON:API version, default `jsonapi.meta`, default paginator, encoding flag defaults, **and the middleware list** for one API surface. Server selection is framework routing's job (Symfony route prefix → server, or a hand-rolled `$path->startsWith(...)` dispatch). Each server runs its own chain via `Server::handle($request)`. Multiple servers in one app = multiple API versions, each with its own schemas/middleware/config. No global middleware registry; no `SingleServerMiddleware` or select-server middleware in core. |
| 2026-05-16 | `Page` value objects replace yin's `PaginationLinkProviderInterface` + collection-side trait pattern | Pagination state and link-emission move off the collection class and onto strategy-specific `Page` value objects (`PageBasedPage`, `OffsetBasedPage`, `CursorBasedPage`). Collections never carry pagination concerns; no trait, no interface to implement. `Paginator::paginate($queryResult, $params): Page` produces the right `Page` subtype. `DataResponse::make($page)` is the paginated case; the response detects the `Page` type and emits `links.{first,prev,next,last}` and `meta.page.{...}` accordingly. `CursorBasedPage::linkSet()` correctly omits `last` (no count, by design). Pre-1.0 break; yin consumers using the trait pattern migrate to passing a `Page`. |
| 2026-05-16 | Operation handling decoupled from PSR-7 via `JsonApiOperation` value objects and an `OperationHandler` interface | The atomic-operations extension (post-1.0 candidate) dispatches multiple semantic operations from one HTTP request; building this on top of PSR-15 would require synthesising fake PSR-7 requests per atomic operation, which is awkward and forces all handlers to take PSR-7 as a parameter. Decoupling now means atomic ops becomes a post-1.0 dispatcher middleware with no changes to the handler contract. Per-verb operation classes (`FetchResourceOperation`, `CreateResourceOperation`, etc.) carry exactly the fields each verb needs and allow PHPStan to narrow inside `match` dispatch. `Psr7ToOperationHandlerAdapter` wraps an `OperationHandler` as a PSR-15 handler; `Server::dispatch($operation)` invokes the handler programmatically (no PSR-15 chain) — useful for integration tests and the eventual atomic-ops machinery. PSR-15 handlers remain supported as an escape hatch. |
| 2026-05-16 | PHPStan generics are a design default on consumer-visible parametric types | Generics aren't a code-quality nice-to-have; they're the mechanism by which a typed library communicates intent to its consumers' static analysis and IDEs. Default on for `Page<T>`, `DataResponse<T>`, `Field<T>`, constraints like `Each<T>` and `In<T>`, `OperationHandler<TOperation>`, registry lookups, and any other type where the parametric payload is visible across the public boundary. Skip on internal types (consumers don't see them), PSR-* boundary types (impedance with framework code matters more), and cases where `instanceof` / `match` narrows just as well as a template would. Applied at port time, not retroactively. Verified with cherry-picked rules from `phpstan-strict-rules` rather than the full ruleset. |
| 2026-05-16 | Existing `haddowg\JsonApi\Schema\*` namespace renamed to `haddowg\JsonApi\Document\*` in Phase 4.5 | The current layout holds document parts (`AbstractDocument`, `ResourceObject`, `Link`, `Error`, `JsonApiObject`, etc.) — `Document\*` is a more accurate name for the contents. Phase 4.5 introduces a top-level `Schema` fluent type at `haddowg\JsonApi\Schema\Schema`; the rename is for readability, not to resolve a hard FQCN collision (a class can coexist inside its own subnamespace). Mechanical for consumers (search-and-replace); cost is bounded by being pre-1.0. Executes as the first commits of Phase 4.5; can be reverted at kick-off if the maintainer prefers `haddowg\JsonApi\Schema\Schema` as the FQCN. |
| 2026-05-16 | `Map` field ships in core for same-model column spread; `Map::on($relation)` (related-model column spread) is deferred to the Symfony bundle | `Map::on()` requires ORM awareness (load and persist a related model); a framework-agnostic core has none. The Symfony bundle can ship a Doctrine-aware extension that handles this if there's demand. Same-model spread is useful even without an ORM and uses only the field's existing `serializeUsing`/`fillUsing` hooks. |
| 2026-05-31 (Phase 2) | Profiles are **advisory**: a server MUST ignore unrecognized profiles — never `406`. `406`/`415` are reserved for the `ext` parameter (415 = unsupported `ext` on Content-Type; 406 = unusable/unsupported-`ext` Accept). | Corrects the pre-drafted Phase-2 plan against JSON:API 1.1 ("a server MUST ignore any profiles that it does not recognize"). The Phase-3 content-negotiation middleware inherits this: it applies registered profiles and rejects only unsupported extensions. |
| 2026-05-31 (Phase 2) | `ProfileInterface` carries a `finalizeDocument(array,$request): array` hook; profile **application** (apply registered+requested profiles, echo `Content-Type` `profile` param + `links.profile`, run the hook, set `Vary: Accept`) lives in the response layer (`AbstractResponse`), driven by `ServerInterface::profiles()`. | Keeps the profile contract declarative while giving profiles one well-defined mutation point. Phase 3 error-handling/negotiation middleware renders response VOs through the same path, so profiles apply uniformly. |
| 2026-05-31 (Phase 2) | `ext` media-type negotiation is wired now (parse + 415/406 against a server-supported set, empty by default) but **not dispatched**. `RequestValidator(string ...$supportedExtensions)`; request exposes `get{Requested,Applied}Extensions()`. | The drop-in point for the post-1.0 Atomic Operations `ext`: it registers its URI in the supported set and adds a dispatcher; no negotiation rework needed. |
| 2026-05-31 (Phase 2) | Pagination shipped as `Pagination\{Paginator,Page}` (+ strategy/VO subtypes) under `haddowg\JsonApi\Pagination\*`; `CursorPaginator` is standalone (not a `Paginator`) because a cursor page has no total. Cursor pagination is the first end-to-end profile consumer (`CursorPaginationProfile`). Yin's `PaginationLinkProviderInterface` + collection traits are deleted. | Realises the master-plan `Page`-VO decision; `DataResponse::fromPage($page)` is the paginated path. Collections never carry pagination concerns. |
| 2026-05-31 (Phase 4) | Optional, dev/CI JSON Schema validation shipped under `haddowg\JsonApi\Validation\*` (`DocumentValidator`, `SchemaProvider`/`VendoredSchemaProvider`, `SchemaContributingProfile`) + two per-server opt-in PSR-15 middleware (`Request`/`ResponseValidationMiddleware`). Backed by `opis/json-schema` 2.x as `require-dev` + `suggest` (never `require`). Base JSON:API 1.1 schema (draft 2020-12) vendored verbatim under `resources/schemas/` from VGirol/json-api; separate request/response roots; profile fragments and (Phase 4.5) per-resource schemas compose via one `allOf` entry point (`$additionalSchemas`); the base document-root `unevaluatedProperties` is relocated onto the composite so fragments can extend top-level members. Violations map to the existing `Request`/`ResponseBodyInvalidJsonApi` exceptions with `source.pointer`. | Realises the master-plan validation decision (`opis/json-schema` against the base schema; per-resource compiled schema is the Phase-4.5 consumer of the same `allOf` entry point). Validation is opt-in so production consumers don't pay for it; the response value objects' render signatures are untouched. |
| 2026-05-31 (Phase 3) | PSR-15 middleware suite shipped under `haddowg\JsonApi\Middleware\*` (`ContentNegotiationMiddleware`, `RequestBodyParsingMiddleware`, `ErrorHandlerMiddleware`, `JsonApiMiddleware` aggregate). The parsed `JsonApiRequest` is **swapped down the chain** (no request attribute) — `JsonApiRequest` is itself a `ServerRequestInterface`. The error handler is outermost, renders typed exceptions and a `$debug`-gated generic 500 (Laravel-faithful per-error `meta.{exception,file,line,trace}` + `detail`), and **does not** render VOs from the inner handler (PSR-15 `handle(): ResponseInterface` makes that infeasible — the `Psr7ToOperationHandlerAdapter` renders consumer VOs). Negotiation is **request-side only** and takes `string ...$supportedExtensions` (no `Server`), since profiles are advisory and profile/Content-Type emission lives in the response layer. Added `psr/http-server-middleware` + `psr/log`. | Realises the master-plan middleware decision (per-server ownership, error handler outermost). The swap-down-chain and adapter-renders-VO calls resolve the tension between PSR-15's `ResponseInterface` contract and the Phase-1 response-VO separation without coupling VOs to PSR-7. |
| 2026-05-31 (Phase 4.5) | Fluent schema DSL shipped as the recommended public surface under `Resource\*` (fields/constraints/relations/filters/sorts), with `Resource\AbstractResource` satisfying both the `Serializer` (renamed from `Schema\Resource\*`) and `Hydrator` contracts from one `fields()` list. `Server\Server` is the per-API-version config root: immutable value, PSR-15 `RequestHandlerInterface`, `SerializerResolver`, `dispatch()`; composes a `SchemaRegistry` (+ overrides) and the Phase-2 `ProfileRegistry` while keeping the `ServerInterface` render contract unchanged. `Validation\SchemaCompiler` compiles per-resource JSON Schema from constraint metadata into the existing `$additionalSchemas` composition (no validator API change), wired into `RequestValidationMiddleware`. A small public `Testing\*` namespace ships (document/error assertion wrappers, request/operation builders, `assertJsonApiSpecCompliant`). | Realises the master-plan three-layer (schema→serializer→hydrator) and `Server`-as-config-root decisions; the `Resource`/`Serializer` rename (chosen over `Schema\*`→`Document\*`) frees `Resource` for the encapsulating fluent type without a `Document\Document` clash. |

## Tooling baseline

- **CI**: GitHub Actions
- **Test matrix**: PHP 8.3, 8.4, 8.5 × `lowest`, `highest` composer dependency versions (8.5 added at Phase 0 kick-off; 8.3 remains the floor)
- **Test runner**: PHPUnit (latest stable supporting target PHP versions)
- **Static analysis**: PHPStan level 9
- **Code style**: PHP-CS-Fixer with PER-CS 2.0
- **Coverage**: pcov + Codecov upload
- **Releases**: Conventional commits + release-please
- **Dependency updates**: Dependabot
- **Mutation testing**: deferred (Infection, post-1.0)

## Stability commitment

- `0.x`: breaking changes permitted between minor versions; documented in CHANGELOG.
- `1.0`: full semver. Criteria for cutting `1.0` decided by maintainer (the user); minimum bar is a complete, stable core including profiles, middleware, validation, and ported documentation.
- Atomic Operations extension and attribute-driven resources/hydrators are explicitly post-1.0 candidates and are not blockers for cutting 1.0.
- PHP version drops occur only on major version bumps.
