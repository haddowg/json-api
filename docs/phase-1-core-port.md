# Phase 1 — Core Port & Modernise

## Goal & scope

Port the core of woohoolabs/yin into `haddowg/json-api` and modernise it for PHP 8.3+ idioms. By the end of this phase the package provides equivalent JSON:API 1.1 server-side functionality to yin, with a cleaner API surface and a modern internal codebase, ready for higher-level features (profiles, middleware, atomic ops, attributes) to build on top.

**In scope:**

- Port yin's source under `haddowg\JsonApi\…`, keeping the existing namespace structure
- Apply modern language features throughout (readonly properties, enums, typed properties, constructor promotion, first-class callable syntax, named arguments where they aid clarity, `match` over `switch` where appropriate)
- Migrate from `ExceptionFactory` indirection to a typed exception hierarchy
- Drop `SerializerInterface` and inline `json_encode` calls with `JSON_THROW_ON_ERROR`
- Upgrade to PSR-7 v2 interface signatures (return types, parameter types)
- Port yin's existing test suite, modernise (PHPUnit attributes over annotations, typed assertions), and tag with `@group spec:*` markers
- Verify JSON:API 1.1 spec compliance progressively during the port; record gaps in the decision log

**Out of scope:**

- Profile infrastructure (Phase 2)
- PSR-15 middleware (Phase 3)
- Schema validation integration (Phase 4)
- Atomic Operations extension (post-1.0 candidate)
- Attribute-driven resources/hydrators (post-1.0 candidate)
- Documentation rewrites (Phase 5)
- Pagination changes beyond modernisation — leave profile pairing for Phase 2

## Prerequisites

- Phase 0 complete: repository scaffolded, CI green, tooling in place
- Local checkout of [woohoolabs/yin](https://github.com/woohoolabs/yin) for reference (don't depend on it, only mine for structure/tests)

## Kick-off

Before writing any implementation code:

1. Read `docs/phase-0-bootstrap.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. Read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
3. Walk the current state of [woohoolabs/yin](https://github.com/woohoolabs/yin) `master` to confirm the subsystem inventory in the task list is still accurate (yin may have changed since this plan was written).
4. **Forward-compatibility check.** The Phase 4.5 fluent schema layer ships an abstract base `Schema` that implements yin's `Resource` (per-resource-type serializer) and `Hydrator` contracts by walking a field list. The `Resource` + `Hydrator` public surface ported in this phase must be **clean enough that an abstract base can implement them by composition, not inheritance**. Concretely: no leaked internal types in public method signatures; no required behaviour that depends on `parent::` calls into the abstract base; constructor signatures that don't pin consumers to a specific dependency layout. Review the public surface against this constraint before locking it in. Defer awkward yin idioms rather than carry them forward. (Vocabulary note: yin's `Resource` is the per-resource-type serializer class. Yin previously called this `AbstractResourceTransformer` and deprecated it in favour of `AbstractResource`. The JSON:API spec's "resource object" is a different concept — that lives at `Schema\Resource\ResourceObject` in current yin, renamed to `Document\Resource\ResourceObject` in Phase 4.5.)
5. **Heads-up on the Phase 4.5 namespace rename.** Phase 4.5 introduces a top-level fluent `Schema` class at `haddowg\JsonApi\Schema\Schema`. The existing `Schema\*` subdirectories (`Schema\Document\*`, `Schema\Resource\*`, etc.) are likely to be renamed to `Document\*` at the same time, because their contents are document parts and the rename improves readability. The rename is not strictly required (a class can live inside its own subnamespace) but is the current intent; treat the yin layout as ported as-is for this phase, knowing it may move. The rename, if it happens, is mechanical.
6. Revise the task list as needed and commit the plan revision as a single commit before starting implementation.

## Task list

Tasks are grouped by yin subsystem. Within each group, work through file-by-file. Commit incrementally; each commit should leave CI green.

### Pattern documentation (`CLAUDE.md`)

As types are ported, capture the canonical modernisation pattern for each kind of component in a `CLAUDE.md` at the repository root. This file is read by future Claude Code sessions (including after context compaction or session restart) and ensures consistency across the port. It is not consumer-facing documentation — it is an executor-facing playbook.

- [x] Create `CLAUDE.md` at repository root with the following sections:
  - **Project orientation** — one paragraph: what the package is, what it forks, where the spec lives.
  - **Operational rules** — the batching/worktree/convergence rules from the master plan's "Required operational rules in `CLAUDE.md`" section. Copy them in verbatim so executors don't need to chase across files.
  - **Type system principles** — short standing guidance on when to use PHPStan generics and when not. Default to generics on consumer-visible parametric types (`Page<T>`, `DataResponse<T>`, `Field<T>`, `Each<T>`, `In<T>`, `OperationHandler<TOperation>`, registry lookup methods with `class-string<T>` → narrowed return type, etc.). Skip generics on internal types, on PSR-* boundary types, and where `instanceof` / `match` already narrows just as well as a template parameter would. Apply at port time alongside each type, not as a retroactive sweep. The full rationale lives in `PLAN.md`'s high-level decisions; this section is the executor-facing shorthand. Include a couple of small code sketches showing the generic and non-generic shapes side by side.
  - **Modernisation patterns** — organised by component kind (see list below); each entry is a paragraph plus minimal code sketch.
- [x] Component kinds to cover under Modernisation patterns. Add an entry the first time a representative is ported; refine it if a later port reveals a better pattern:
  <!-- PROGRESS: all component-kind pattern entries written in CLAUDE.md (value objects, links containers, exceptions, requests, hydrator-relationship VOs, paginators, negotiation, hydrators, resources, serialization-side relationships, serialization engine & internal documents, response value objects + ServerInterface, operations). No Enums entry — none introduced (see decision log). -->
  - **Value objects / data classes** (e.g. `Link`, `ErrorSource`, `JsonApiObject`) — whole-class `readonly class` by default (downgrade to per-property only on a concrete need such as memoization, recorded in the decision log), promoted constructor properties, factory methods over multi-form constructors ✅ *(pattern entry written; first instances ported)*
  - **Internal document classes** (`AbstractDocument` and subclasses) — abstract method contracts, lifecycle, immutability boundary. **Marked `@internal`**; users never subclass these. Pattern entry covers how the response value objects construct and render them.
  - **Response value objects** (`DataResponse`, `MetaResponse`, etc.) — immutable, fluent `with…` methods returning new instances, rendering contract against a `Server`. This is the public response surface; the pattern entry is the canonical reference for adding new response types post-1.0.
  - **Operations** (the per-verb `JsonApiOperation` family — `FetchResourceOperation`, `CreateResourceOperation`, etc.) — readonly value objects, one class per verb, common `JsonApiOperation` interface, `Target` + `QueryParameters` + `OperationContext` shared shape. Pattern entry covers what an operation carries, when to add a new verb (post-1.0 atomic-ops adds three), and how the `OperationHandler` dispatches via `match` on operation type.
  - **Resources** (yin's per-resource-type serializer classes, `AbstractResource` / `ResourceInterface`) — class shape, method contracts, included-relationship handling. Note for executors: yin's legacy `AbstractResourceTransformer` is **not** ported; only the current `AbstractResource` is.
  - **Hydrators** — class shape, method contracts, request → domain-object flow. Helper traits are ported as **instance-method traits** (drop yin's `static`; convert `self::`/`static::` call sites to `$this->`); traits stay a code-sharing convenience for the inheritance path, while the contract itself remains implementable by composition.
  - **Exceptions** — interface implementation, `getErrors(): list<Error>` + `getStatusCode(): int` shape, status mapping, naming convention
  - **Enums** — when to introduce one (replacing class constants), naming, backed vs pure
  - **Negotiation parsers** — input/output shape, error throwing pattern
  - **Paginators** — class shape, link-emission contract, profile-association pattern (refined in Phase 2)
- [x] Each pattern entry should be short (a paragraph + a minimal code sketch). Treat it like a style guide; if it gets long, the abstraction is wrong.
- [x] Apply the operational rules as work proceeds: port the first instance of each component kind sequentially, write the pattern entry, then batch the remaining instances of that kind to subagents in separate worktrees. Do not fan out before the pattern exists. After every fan-out, run a consolidation review against the pattern entry before starting any further batch; record the outcome in the decision log.
- [x] Update `CLAUDE.md` whenever a port reveals a refinement to an established pattern; the previous pattern entry is replaced, with a one-line note in the decision log explaining the shift. If a refinement surfaces mid-batch, halt the batch, update the playbook, then resume.
- [x] At phase close, walk every section of `CLAUDE.md` against the ported code and confirm the patterns are still accurate

### Foundational types

- [x] Port `JsonApi/Request/RequestInterface` and concrete `JsonApiRequest` — modernise to use PSR-7 v2 signatures
- [x] **Yin's `Responder` is not ported as a public class.** Its responsibility — turning a domain-level result into a PSR-7 response carrying a JSON:API document — moves into the rendering paths of the response value objects (see "Response value objects" below). If a sliver of internal helper code remains useful, port it as an internal type; the public surface is the response value objects, not a separate responder.
- [x] **Do not port yin's `JsonApi` orchestrator class.** Its role as the user entry point is superseded: the response value objects (`DataResponse::make()` etc.) are the response surface and render against a `Server` directly; `Server` is the config/dispatch root. There is no `respond()->ok(...)` facade. Record the drop in the decision log (mirrors the `Responder` and `AbstractSimpleResourceDocument` drops).
- [x] Port enums for HTTP status, error categories etc. where yin uses class constants (introduce native enums)

### Exception hierarchy (replaces `ExceptionFactory`)

- [x] Define `haddowg\JsonApi\Exception\JsonApiException` interface (extending `\Throwable`) with `getErrors(): list<Error>` and `getStatusCode(): int`. The exception carries error *data*, not a built document; document construction is owned by the `ErrorResponse` value object / error-handler middleware, which consume `getErrors()`.
- [x] Port each exception yin's `DefaultExceptionFactory` produces as a concrete class implementing the interface (e.g. `ResourceNotFound`, `MediaTypeUnsupported`, `MediaTypeUnacceptable`, `ResourceTypeUnacceptable`, `RelationshipNotExists`, etc.). Maintain a checklist mapping each `DefaultExceptionFactory` method → new exception class to confirm full coverage. <!-- all 33 ported; coverage checklist verified in fan-out -->
- [x] Replace all internal `$exceptionFactory->create…()` calls with `throw new …()` <!-- no internal call sites ported yet; factory not ported, so nothing to replace at this point -->
- [x] Delete `ExceptionFactoryInterface` and `DefaultExceptionFactory` (do not port) <!-- never ported -->
- [x] Document the exception → HTTP status mapping in a single source-of-truth location (will become docs/exceptions.md in the docs phase)

### Document & schema (internal types)

Documents are **internal types** in this package — consumers never subclass them. The public response surface is the response value objects (see next section). The document classes are ported because they're the right internal vehicle for emitting JSON:API document bodies, but they don't appear in PHPDoc examples, getting-started docs, or anywhere a consumer would import from.

- [x] Port `JsonApi/Schema/Document/AbstractDocument`, `AbstractSingleResourceDocument`, `AbstractCollectionDocument`, `ErrorDocument`. Mark as `@internal` in PHPDoc. Recommendation: move to a `haddowg\JsonApi\Document\Internal\*` (or just `Internal\Document\*`) namespace at port time so the API boundary is clear; decide at kick-off.
- [x] **Do not port `AbstractSimpleResourceDocument`.** Yin's own docs flag it as a footgun ("doesn't support sparse fieldsets, automatic inclusion of related resources"); the Phase 4.5 schema layer makes writing a proper resource near-free, eliminating the need for a "simple" shortcut. Record the drop in the decision log.
- [x] Port `JsonApi/Schema/Resource/*` (ResourceObject, ResourceIdentifier, etc.) <!-- ResourceIdentifier done: src/Schema/ResourceIdentifier.php, fromArray() throws typed ResourceIdentifier* exceptions directly (ExceptionFactory dropped). ResourceObject et al. outstanding. -->
- [x] Port `JsonApi/Schema/Link/*` (Link, DocumentLinks, ResourceLinks, RelationshipLinks, etc.)
- [x] **Link audit.** During the port, confirm two things about the link types:
  - Custom link keys (beyond the spec's `self` / `related` / `first` / `prev` / `next` / `last` / `describedby` / etc.) can be set alongside the spec-defined ones. The spec allows arbitrary keys; consumers shouldn't have to fight the types to add custom relations.
  - URI templates (RFC 6570) are representable on `Link`. The JSON:API link object form is `{href, meta}` plus optionally a templated indicator; if yin's `Link` doesn't already support template-shaped values, add the support during the port. Small addition.
- [x] Port `JsonApi/Schema/Relationship/*` (ToOneRelationship, ToManyRelationship)
- [x] Port `JsonApi/Schema/JsonApiObject`, `Error`, `ErrorSource`
- [x] Port `JsonApi/Schema/Meta` handling
- [x] Apply `readonly` to value-object-like classes; keep mutability only where genuinely needed.
- [x] **Document hierarchy audit.** As documents are being ported, apply boilerplate-reduction consistent with documents being per-request stateful response objects but internal-only:
  - Default `getJsonApi()` to `new JsonApiObject('1.1')` in `AbstractDocument`; subclasses override only for custom `jsonapi.meta`.
  - Standardise the absent-member convention: nullable return types throughout, with `null` meaning "omit." Decide between `?Meta` and `array` returning `[]`; record the choice in the decision log.
  - Decouple pagination link generation from the collection class. Pagination links emit from `Page` value objects (Phase 2) rather than from `DocumentLinks::setPagination($uri, $collection)` requiring `PaginationLinkProviderInterface` on the collection. Coordinate the deletion of `PaginationLinkProviderInterface` with the Phase 2 paginator refactor.

### Response value objects (public API)

The public surface for "return a JSON:API response" is a small set of immutable response value objects. Consumers never construct documents directly. The orchestrator's `Responder` class either shrinks dramatically or disappears entirely; its responsibility moves into these objects' rendering paths.

- [x] `haddowg\JsonApi\Response\DataResponse` — wraps a model, iterable, `Page`, or `null` as the `data` member. The 95% case.
- [x] `haddowg\JsonApi\Response\MetaResponse` — wraps top-level meta, no `data` member. Spec-allowed.
- [x] `haddowg\JsonApi\Response\RelatedResponse` — for `GET /api/posts/1/tags` (related resources of a relationship). Carries the parent model, the relationship name, and the related data.
- [x] `haddowg\JsonApi\Response\IdentifierResponse` — for `GET /api/posts/1/relationships/tags` (resource identifiers only, no full resource serialization).
- [x] `haddowg\JsonApi\Response\ErrorResponse` — wraps one or more `JsonApiException` instances (or already-built `Error` value objects). The error handler middleware in Phase 3 produces these from caught exceptions; consumers can also construct them directly.
- [x] Each response value object has fluent `withMeta(array $meta)`, `withLinks(Link|Links $links)`, `withJsonApi(JsonApiObject $jsonApi)`, `withHeader(string $name, string $value)`, `withHeaders(array $headers)`, `withEncodeOptions(int $flags)` methods. All return new immutable instances.
- [x] Each response value object has a rendering contract — given a `Server` (from Phase 4.5; in Phase 1 a thin placeholder or test fixture) and the active request, produces a PSR-7 response. The contract should be future-compatible with the Phase 4.5 `Server` so that Phase 4.5 doesn't have to revise the response signatures.
- [x] Response value objects can be returned from a PSR-15 inner handler — the error handler middleware (Phase 3, amended) detects them and renders. Or rendered explicitly via `->toPsrResponse($server, $request)`.
- [x] **Document hierarchy and response value objects are tested together.** A throwaway test fixture in `tests/` constructs each response type, configures it, renders it against a minimal server, and asserts the output JSON. Both single and collection paths covered.

### Operation abstraction (public API)

To keep handlers decoupled from PSR-7, the package introduces a `JsonApiOperation` value object representing one semantic JSON:API operation (fetch a resource, create a resource, update a relationship, etc.). The recommended consumer-facing handler interface, `OperationHandler`, takes a `JsonApiOperation` — not a PSR-7 request — and returns a response value object. A wrapper adapter bridges PSR-15 and `OperationHandler` at the chain's edge.

This shape is forward-compatible with the post-1.0 Atomic Operations extension: an atomic-ops dispatcher (post-1.0) constructs multiple `JsonApiOperation` instances from one PSR-7 request and dispatches each through the same `OperationHandler`, without any operation needing a synthetic PSR-7 request. Operations are also useful in 1.0 for integration tests and any consumer wanting to invoke JSON:API logic programmatically without HTTP.

- [x] **Verb type.** Ship `JsonApiOperation` as an interface (`haddowg\JsonApi\Operation\JsonApiOperation`) plus a small family of per-verb implementations. One class per verb:
  - `FetchResourceOperation` (`GET /posts` or `GET /posts/1`)
  - `CreateResourceOperation` (`POST /posts`)
  - `UpdateResourceOperation` (`PATCH /posts/1`)
  - `DeleteResourceOperation` (`DELETE /posts/1`)
  - `FetchRelationshipOperation` (`GET /posts/1/relationships/author`)
  - `FetchRelatedOperation` (`GET /posts/1/author`)
  - `UpdateRelationshipOperation` (`PATCH /posts/1/relationships/author`)
  - `AddToRelationshipOperation` (`POST /posts/1/relationships/tags`)
  - `RemoveFromRelationshipOperation` (`DELETE /posts/1/relationships/tags`)

  Per-verb classes (rather than a single class with a `Verb` enum field) let each operation carry exactly the fields it needs — a `FetchResourceOperation` has no request body field; a `CreateResourceOperation` has no resource id field; etc. Handlers can dispatch via `match (true) { $op instanceof CreateResourceOperation => …, … }` with PHPStan narrowing each branch.
- [x] **Common shape.** Every operation implements `JsonApiOperation` and exposes at minimum:
  - `target(): Target` — a small value object: resource type (string), optional resource id, optional relationship name. Covers every URL shape in the JSON:API spec.
  - `queryParameters(): QueryParameters` — parsed value object holding sparse fieldsets, includes, sorts, filters, and pagination params (already exists in Phase 1's negotiation/parsing work; the operation references it).
  - `context(): OperationContext` — typed bag carrying the active `Server`, the originating PSR-7 request (nullable; populated for HTTP-originated operations, `null` for programmatically-dispatched or atomic-batch operations), and any adapter-thread-through state.
- [x] **Per-verb-specific fields.** Operations that have a request body (create, update, update-relationship, add-to-relationship, remove-from-relationship) expose `body(): JsonApiRequest` (the parsed JSON:API request document — Phase 1 already ports this).
- [x] **`OperationHandler` interface.** The recommended consumer-facing handler shape:
  ```php
  interface OperationHandler
  {
      public function handle(JsonApiOperation $operation): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|ErrorResponse;
  }
  ```
  The handler does not import PSR-7. It can reach the originating request via `$operation->context()->httpRequest()` when genuinely needed (file uploads, custom headers, framework auth context), but most handlers won't.

  The return type union is the response value object set defined above. Post-1.0 atomic operations will introduce a `OperationResult` supertype that the response value objects implement plus an `AtomicOperationResult` variant for the within-batch case; the union stays valid because each member becomes an `OperationResult`. No 1.0 breaking change.
- [x] **`Psr7ToOperationHandlerAdapter`** — a small class implementing PSR-15 `RequestHandlerInterface` that wraps an `OperationHandler`. Translates the PSR-7 request into the appropriate per-verb `JsonApiOperation` (using the parsed JSON:API request representation attached by Phase 3's body-parsing middleware, plus the active `Server` from the request attribute or constructor), invokes `$handler->handle($operation)`, takes the returned response value object, renders to PSR-7 against the `Server`. This is what gets passed as the innermost handler when a consumer wires the middleware stack — see Phase 3 for the integration.
- [x] **PSR-15 handlers remain supported.** Consumers who want to write a traditional `RequestHandlerInterface` (e.g. for framework router integration that hands them PSR-7) can do so; the `Server` accepts either an `OperationHandler` (wrapped in the adapter automatically) or a PSR-15 handler directly. The recommended path is `OperationHandler`; PSR-15 is an escape hatch.
- [x] **Programmatic dispatch.** Construct an operation directly, dispatch via `$server->dispatch($operation): DataResponse|...`. Useful for integration tests (no need to mount the middleware stack), internal calls, and post-1.0 atomic-ops machinery. The `OperationContext::httpRequest()` returns `null` for programmatically-dispatched operations; document this clearly so consumers don't reach for the HTTP request expecting it to be there.
- [x] **Unit tests** for each per-verb operation class (construction, accessor behaviour, immutability). **Integration test** for `Psr7ToOperationHandlerAdapter` end-to-end (PSR-7 in → operation parsed → handler called → response value object returned → PSR-7 out). **Programmatic dispatch test** demonstrating an operation constructed and dispatched without any PSR-7 involvement.

### Resource

- [x] Port `JsonApi/Schema/Resource/AbstractResource` (yin's per-resource-type serializer) and `ResourceInterface`, and any supporting traits used by current `AbstractResource` (not by the deprecated `AbstractResourceTransformer`).
- [x] ~~Do **not** port `JsonApi/Transformer/AbstractResourceTransformer` or `Transformer/ResourceTransformerInterface`.~~ **Kick-off finding (recorded in decision log):** those deprecated classes are already gone from yin master. Today's `Transformer/` is the live internal serialization engine — see the new "Serialization engine (internal types)" group below.
- [x] Port included-relationship/sparse-fieldset logic from `AbstractResource`.
- [x] Keep class-based API as primary entry point (attribute layer is a post-1.0 candidate).

### Serialization engine (internal types)

Added at kick-off after the yin walk revealed `Transformer/` is yin's live internal serialization engine, not dead deprecated code (see decision log). These types are **`@internal`**: they back the documents and `AbstractResource` but are never part of the consumer surface. Modernise syntax; preserve the spec-sensitive behaviour (compound-document inclusion, sparse fieldsets, included-resource dedup) verbatim, guarded by the ported tests.

- [x] Port `JsonApi/Schema/Data/*` (`DataInterface`, `AbstractData`, `SingleResourceData`, `CollectionData`) — the accumulator for primary + included resources during serialization. Mark `@internal`.
- [x] Port `JsonApi/Transformer/*` (`AbstractDocumentTransformation`, `DocumentTransformer`, `ResourceTransformer`, `ResourceTransformation`, `ResourceDocumentTransformation`, `ErrorDocumentTransformation`) and the root-level `TransformerTrait` and `Utils` helpers. Mark `@internal`. Fold `TransformerTrait`/`Utils` into a sensible internal namespace (`Schema\Serialization\*` or similar — decide at port time, record in decision log) rather than carrying root-level `src/` files.
- [x] Replace `Serializer/Deserializer` indirection: drop `SerializerInterface`/`DeserializerInterface`/`JsonSerializer`/`JsonDeserializer`; inline `json_encode`/`json_decode` with `JSON_THROW_ON_ERROR` at the engine boundary.
- [x] Port included-relationship/sparse-fieldset/dedup logic with the engine; its tests are the spec-compliance backbone for `spec:inclusion-of-related-resources` and `spec:sparse-fieldsets`.

### Hydrator

- [x] Port `JsonApi/Hydrator/AbstractHydrator`, `CreateHydratorTrait`, `UpdateHydratorTrait` (or modern equivalents)
- [x] Port `JsonApi/Hydrator/Relationship/*` types
- [x] Replace exception factory dependencies with typed exception throws

### Negotiation

- [x] Port `JsonApi/Negotiation/RequestValidator` (the parts that do content-type/accept negotiation only — JSON-schema body validation deferred to Phase 4)
- [x] Port `JsonApi/Negotiation/ResponseValidator` similarly trimmed
- [x] Verify that content-type and Accept header handling correctly applies JSON:API 1.1 semantics (no parameters except `ext` and `profile` are spec-significant; reject unknown parameters per spec)

### Pagination

- [x] Port the request-side pagination parsers `JsonApi/Request/Pagination/*` (`PageBasedPagination`, `OffsetBasedPagination`, `CursorBasedPagination`, `FixedPageBasedPagination`, `FixedCursorBasedPagination`, `PaginationFactory`) — these read the `page[...]` query params.
- [x] Port the link-provider side `JsonApi/Schema/Pagination/*` (the `*PaginationLinkProviderTrait` family + `PaginationLinkProviderInterface`). Modernise the traits to instance-method traits per the established pattern. **Coordinate `PaginationLinkProviderInterface` deletion with the Phase 2 paginator refactor** (it is replaced by `Page` value objects); for Phase 1 port it as-is to keep the collection-document path working, leaving a TODO referencing Phase 2.
- [x] Modernise internals only; profile association deferred to Phase 2 (leave a TODO comment referencing Phase 2 where appropriate)

### Tests

Tests are ported file-by-file alongside their implementations (per the master plan's operational rules), not in a deferred bulk pass at end of phase. The items below are cross-cutting concerns that apply across all ported test files.

- [x] Establish the `tests/` directory layout to mirror `src/` so the file-by-file pairing is mechanical <!-- tests/Schema/... mirrors src/Schema/... -->
- [x] Convention: for every source file ported, port the corresponding yin test file in the same commit (or an adjacent commit on the same branch); the implementation is not considered ported until its tests are green under the new API <!-- ongoing convention, followed for VOs so far -->
- [x] Convert PHPUnit docblock annotations (`@test`, `@dataProvider`) to PHPUnit attributes (`#[Test]`, `#[DataProvider]`) as each test is ported <!-- ongoing; done for VOs -->
- [x] Add `#[Group('spec:<section>')]` to each test that asserts a spec behaviour, as each test is ported. Use spec anchor names (e.g. `spec:document-structure`, `spec:fetching-data`, `spec:fetching-resources`, `spec:fetching-relationships`, `spec:inclusion-of-related-resources`, `spec:sparse-fieldsets`, `spec:sorting`, `spec:pagination`, `spec:filtering`, `spec:crud`, `spec:errors`, `spec:content-negotiation`). <!-- ongoing; done for VOs (spec:document-structure, spec:errors) -->
- [x] Record yin's published coverage figure in the decision log before porting begins; that figure is the floor for this phase <!-- ~100%, recorded in decision log -->
- [x] Ensure all ported tests pass on PHP 8.3 and 8.4 against `lowest` and `highest` dependency strategies (verified by the standing CI matrix, not a one-shot check at phase close)
- [x] If a yin test asserts behaviour that the new typed-exception or otherwise-modernised API no longer surfaces, rewrite the test to assert the new equivalent rather than skipping it; record the rewrite in the decision log so spec coverage isn't silently lost

### Spec compliance verification (progressive)

- [x] Maintain `docs/spec-compliance.md` (created during this phase) <!-- created; seeded with document-structure + errors coverage. Keep filling as subsystems land. --> — a living checklist of JSON:API 1.1 normative requirements (MUST/SHOULD) with status: covered-by-test, covered-by-code-only, not-covered, intentionally-unsupported. Include a short preamble noting that this document tracks **JSON:API spec compliance only**; OpenAPI spec generation (a post-1.0 candidate) is a separate concern and should not be conflated with it.
- [x] As each subsystem is ported, fill in the relevant rows
- [x] At end of phase, the document is the truth-of-record for the spec compliance gap

### API surface review

- [x] Walk the public API once everything is ported; flag any awkward or redundant surface area in the decision log for resolution before phase close
- [x] Confirm no remnants of `SerializerInterface` or `ExceptionFactoryInterface` in any public-facing type
- [x] **Confirm yin's `Resource` (serializer) and `Hydrator` contracts can be implemented by composition.** Concretely: write a throwaway test fixture class (in `tests/`) that implements both `ResourceInterface` and the hydrator contract directly — no inheritance from `AbstractResource` or `AbstractHydrator` — and confirm it can be constructed, registered, and exercised end-to-end. This proves the contracts are public API, not just internal vehicles for the port, and that the Phase 4.5 schema base can implement them on top.
- [x] **Generics coverage sanity check.** Walk every public type introduced this phase and confirm it carries a `@template` parameter where the type carries a consumer-visible parametric payload (response value objects, per-verb operations, the placeholder `Server`). Skipped intentionally on internal types, PSR-* boundaries, and types where `instanceof` narrows just as well — record any skip rationale in the decision log. The CLAUDE.md "Type system principles" section is the reference.
- [x] **Enable cherry-picked `phpstan-strict-rules`.** Add only the generics-related rules from `phpstan-strict-rules` to `phpstan.neon.dist` (or the equivalent vendor-extension config); leave non-generic rules (`noVariableVariables`, etc.) off. Confirm CI green with the additions.

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| 2026-05-30 | Pin `phpunit/phpunit: ^12`; native attributes only (annotations removed in 12) | PHPUnit 12 supports PHP 8.3 + 8.4; PHPUnit 13 drops 8.3 (requires 8.4+/8.5). 8.3 is our floor. Bump to 13 when 8.3 is dropped on a major. | this phase |
| 2026-05-30 | Value objects use whole-class `readonly class` by default | Simplest, strongest immutability guarantee; compatible with the fluent `with…`/clone pattern. Downgrade an individual class to per-property only on concrete need (e.g. memoization). | this phase |
| 2026-05-30 | Port yin's helper traits as instance-method traits (drop `static`, `self::`/`static::` → `$this->`) | Keeps code-sharing ergonomics without static-state/`static::` footguns; mockable; contract stays composition-implementable (no-inheritance fixture proves it). | this phase |
| 2026-05-30 | `JsonApiException` exposes `getErrors(): list<Error>` + `getStatusCode(): int`; no `toErrorDocument()` | Exceptions carry error *data*, not built (internal) documents. `ErrorResponse`/error-handler middleware own document construction and handle caught exceptions and direct `Error[]` via one path. | this phase, Phase 3, Phase 4 |
| 2026-05-30 | Do not port yin's `JsonApi` orchestrator class | Its state lives on `Server`; VOs render against `Server` directly. A `respond()` facade would be a redundant shadow of `Server`. VOs + `Server` are the single public surface. | this phase, Phase 5 |
| 2026-05-30 (kick-off) | **Server placeholder = a minimal `ServerInterface`** shipped this phase under `haddowg\JsonApi\Server`, exposing only what the response value objects read to render (base URI, JSON:API version, default `jsonapi.meta`, default encode options). Render signature is `toPsrResponse(ServerInterface $server, ServerRequestInterface $request)`. | Option (a) from Open questions. Phase 4.5's concrete `Server` implements (a superset of) this interface, so response render signatures never change across phases. | this phase, Phase 4.5 |
| 2026-05-30 (kick-off) | **Internal document classes stay under `haddowg\JsonApi\Schema\Document\*`** (not moved to an `Internal\` subnamespace), marked `@internal` in PHPDoc only. | Closest to yin's layout; the Phase 4.5 `Schema`-namespace rename will relocate `Schema\Document\*` → `Document\*` mechanically anyway, so a separate `Internal\` move now would be churn. The `@internal` tag is the API-boundary signal. | this phase, Phase 4.5 |
| 2026-05-30 (kick-off) | **Standardise absent _structured_ members on nullable-everywhere**: `?Links`/`?DocumentLinks`, `?JsonApiObject`, `?ErrorSource`, etc., with `null` meaning "omit the member." **`meta` is the one documented exception: it stays a plain `array<string,mixed>` with `[]` meaning omit.** | yin mixes conventions (`getMeta(): array` returning `[]` vs `getLinks(): ?DocumentLinks`). Uniform nullability on structured members is explicit, composes with `readonly`, and narrows cleanly under PHPStan level 9. `meta` is deliberately free-form in the spec; there is no semantic difference between `null` and `[]` for it, and a `Meta` wrapper would tax the most common call (`withMeta([...])`) for no type-safety gain. Matches yin and Laravel JSON:API. | this phase |
| 2026-05-30 (kick-off) | **Coverage floor = ~100% line coverage** (yin's published Codecov figure; yin's README badge reports 100%). Tracked concretely against our own Codecov number once the ported suite runs; treated as the floor per acceptance criterion 7. | yin ports its full test suite alongside source file-by-file, so parity coverage is expected. Recorded before porting begins per the Tests task. | this phase |
| 2026-05-30 (kick-off) | **Port yin's internal serialization engine** (`Transformer/*`, `Schema/Data/*`, root `TransformerTrait`, `Utils`) as modernised `@internal` machinery behind the documents/resources, rather than rewriting serialization inline. | Kick-off yin walk found the plan's mental model was stale: the deprecated `AbstractResourceTransformer`/`ResourceTransformerInterface` are **already removed** from yin master, and `Transformer/` is now the **live** internal engine that `AbstractResource` and the documents delegate to for compound-document inclusion, sparse fieldsets, and included-resource dedup. The plan had no tasks for it. Porting it as internal machinery keeps the spec-sensitive logic battle-tested while we modernise syntax; collapsing the indirection is revisited in Phase 4.5 once the ported test suite covers it. | this phase, Phase 4.5 |
| 2026-05-30 | **Link audit — URI templates (RFC 6570) need no extra member** | JSON:API 1.1 has no `templated` boolean (unlike HAL); a templated link is simply a string `href`, so it is representable as-is. | this phase |
| 2026-05-30 | **Link audit — extended `LinkObject` with the full JSON:API 1.1 link-object string members** `rel`, `title`, `type`, `hreflang` (each `string`, omitted from `transform()` when empty) alongside `href`/`meta` | yin only modelled `href`+`meta`. Custom relation keys are unconstrained at the leaf level. | this phase |
| 2026-05-30 | **Link audit — `describedby` member deferred** | It nests a `Link` and the Links container types are not yet ported; a `// TODO` is left in `LinkObject` to add it when those land. | this phase |
| 2026-05-30 | **JSON:API spec version is a single-source-of-truth constant** `JsonApiObject::VERSION = '1.1'`, used as the `JsonApiObject` `$version` default; no repeated `'1.1'` literals. | Avoids drift; one place to bump when the targeted spec version changes. Later types (`ServerInterface` default, negotiation) reference the same constant. | this phase |
| 2026-05-30 | **First parallel fan-out (two worktrees): exception hierarchy + Links containers, on the shared `Error`/`AbstractLinks` base.** Both batches built and self-verified green in isolated worktrees, then converged into the working branch sequentially. | The two batches sit on opposite sides of the `Error`↔exception dependency, so `Error`+`AbstractLinks`+`ErrorLinks` were ported single-threaded first as the shared base, satisfying the "one component kind per fan-out / convergence is sequential" rules. | this phase |
| 2026-05-30 | **Commit signing only works from the primary worktree; linked worktrees fail signing.** Convergence method: agents produce + self-verify in their worktree; the orchestrator copies the verified files into the primary checkout and commits there. | Environment signing server returns HTTP 400 for commits originating in linked worktrees. Copy-and-commit-in-main is the reliable path; the agent's branch is left as a verification artefact. | this phase |
| 2026-05-30 | **Consolidation review (post-fan-out): exception `detail` strings kept as literal yin text, NOT normalised to `$this->getMessage()`.** Removed one dead `use Exception;` import from `AbstractJsonApiException`. | Review found the literal-vs-`getMessage()` variation is legitimate yin fidelity, not drift: yin's error `detail` usually differs from the thrown message (e.g. appends "by the endpoint"). Only ~6 of 33 are identical. Forcing uniformity would corrupt spec-surface text. CLAUDE.md Exceptions entry updated to state the rule. | this phase |
| 2026-05-30 | **Hydrator layer ported** (`HydratorInterface`, `HydratorTrait`/`CreateHydratorTrait`/`UpdateHydratorTrait` as instance-method traits, `AbstractHydrator`/`AbstractCreateHydrator`/`AbstractUpdateHydrator`, `UpdateRelationshipHydratorInterface`). `ExceptionFactory` dropped from every signature → direct typed throws (`ResourceTypeMissing`/`ResourceTypeUnacceptable`/`DataMemberMissing`/`ResourceIdInvalid`/`ResourceIdMissing`/`ClientGeneratedIdNotSupported`/`RelationshipTypeInappropriate`/`RelationshipNotExists`). Consumes the request + relationship VOs via public properties; cardinality validated via reflection on the hydrator callable's 2nd-param hint. Contract is composition-implementable (proven by the test doubles). Establishes the `Hydrators` CLAUDE.md entry. | this phase |
| 2026-05-30 | **Hydrator decoded-JSON narrowing (PHPStan L9).** The sub-agent's worktree reported phpstan-clean but a clean run in the primary tree surfaced ~10 `argument.type` errors (a stale worktree result-cache had masked trait-in-context analysis). Fixed at source: `\is_string`/`\is_array` guards on `type`/`id`/`attributes`/`relationships`/relationship-`data` (a non-string `type`/`id` is malformed and throws the typed exception — spec-correct, not silencing), with an inline `@var array<string, mixed>` only at the `ResourceIdentifier::fromArray()` boundary (matches `ResourceIdentifier` precedent); removed one `(array)` cast in favour of an `is_array` guard. **Convergence lesson:** always re-run the full toolchain in the primary tree after copying sub-agent output — worktree PHPStan caches can hide trait-context errors. | this phase |
| 2026-05-31 | **Closeout — no native enums introduced.** The "port enums where yin uses class constants" task is satisfied vacuously: the ported surface had no compelling class-constant case — HTTP statuses stay plain `int` (matching yin; carried on exceptions), and relationship cardinality stays internal `'to-one'`/`'to-many'` strings within the transformer/hydrator (not consumer-visible). No `Enums` pattern entry in CLAUDE.md as a result. Revisit if a later phase surfaces a consumer-facing enumerable. | this phase |
| 2026-05-31 | **Phase 1 closeout complete.** All task-list items ticked; full toolchain green locally (600 tests / PHPStan L9 no-baseline / PER-CS 2.0). CLAUDE.md carries a pattern entry for every component kind built and was walked for accuracy. Remaining tracked **minor** spec gaps (documented in `docs/spec-compliance.md`, not Phase-1 blockers): link-object `describedby` member (TODO in `LinkObject`; blocker now cleared, deferrable enhancement) and error `source.header` member. CI-matrix confirmation (8.3/8.4/8.5 × lowest/highest) is the one check not runnable locally — pending on the pushed branch. | this phase |
| 2026-05-31 | **Post-closeout — three tracked gaps closed (supersedes the "minor gaps"/"relationship-endpoint limitation" notes).** (1) `LinkObject` now carries an optional `?Link $describedby` emitted by `transform()` — placed **after** the `$meta` ctor param so `ProfileLinkObject`'s positional `parent::__construct(... $meta)` still binds correctly. (2) `ErrorSource` models the `header` member (+ `fromHeader()` ctor). (3) `DocumentTransformer::transformRelationshipDocument()` now also runs `transformMetaMembers()`, which was made **merge-aware** (document-level `meta`/`links` merge *under* the relationship's own, so both appear; jsonapi added) — a deliberate deviation from yin, which omitted top-level members on relationship endpoints. Tests: `LinkObjectDescribedbyTest`, `ErrorSourceHeaderTest`, `RelationshipDocumentMetaTest`. 609 tests green. | this phase |
| 2026-05-31 | **Closeout — generics sanity + `phpstan-strict-rules` finding.** Walked every public type: the only `@template` in the package is the internal `AbstractDocumentTransformation<TDocument>` (validated by L9). Consumer types are deliberately **non-generic** with recorded rationale: `ResourceInterface`/`AbstractResource` serialize `mixed` (arrays included), so the response VOs (`DataResponse` etc.) have no `T` to flow — CLAUDE.md's `DataResponse<T>` was an aspirational example superseded by the `mixed`-resource decision; operations carry concrete fields + a fixed return union; `ServerInterface` is a flat config contract. **`phpstan-strict-rules` has NO generics-specific rule** — its 37 hits here are all stylistic/behavioural (`empty.notAllowed`×21, method variance on legitimate PSR-7 withers, short-ternary, …), exactly the "non-generic rules" the plan said to leave off; and `checkGenericClassInNonGenericObjectType` is a removed-in-PHPStan-2.x option (the check is now intrinsic to the level). Net: generics correctness is fully enforced by **level 9** as-is; the strict-rules dev-dep was added then removed (nothing to cherry-pick). | this phase |
| 2026-05-31 | **Closeout — API-surface review.** Confirmed **no `SerializerInterface`/`DeserializerInterface`/`ExceptionFactory*` types** anywhere in `src/` (only doc-prose noting their removal) and no `WoohooLabs` leakage beyond the yin-credit `@see`. Deduped the JSON:API media-type-parameter regex (duplicated in `JsonApiRequest::isValidMediaTypeHeader()` and `ResponseValidator::validateContentTypeHeader()`) into a single `@internal Request\MediaType::isValid()` — single source of truth for the profile-only rule; both call sites delegate. | this phase |
| 2026-05-31 | **Operations layer built (our design, not a yin port).** `JsonApiOperation` interface + 9 per-verb `final readonly` operations (5 with `body()`), the shared `Target`/`QueryParameters`/`OperationContext` VOs, the `OperationHandler` interface (returns the 5-response union), and `Psr7ToOperationHandlerAdapter` (PSR-15). The adapter reads `Target` from the `Target::class` request attribute (routing supplies it in Phase 3) and dispatches via an HTTP-method × target-shape `match`. **Decisions:** missing-`Target` → rendered 500 `ErrorResponse` (server-wiring fault, not a throw — keeps the PSR-15 contract yielding a JSON:API response); non-CRUD verb → `ApplicationError`. `OperationContext::httpRequest()` is nullable (`null` for programmatic dispatch). No generics (concrete fields + fixed return union). **Added `psr/http-server-handler: ^1.0`** (PSR-15 `RequestHandlerInterface` was not previously a direct dependency; composer.json + lock updated via `composer require`). | this phase, Phase 3 |
| 2026-05-31 | **Pagination link-provider traits ported** (`Schema\Pagination\*PaginationLinkProviderTrait` + `PaginationLinkProviderInterface`) as instance-method traits building first/prev/next/last/self links; `Utils::getUri` ported into `@internal Transformer\Utils` (the only `Utils` method still needed — the rest stays unported). Phase-2 `// TODO`-marked on every trait + the interface (fold into `Page`; interface deletion coordinated with the Phase-2 paginator refactor). | this phase, Phase 2 |
| 2026-05-31 | **`RelatedResponse` + `IdentifierResponse` complete the 5-VO public set.** `RelatedResponse` (related-resources endpoint) renders the related data exactly like `DataResponse` (named ctors `fromResource`/`fromCollection`) and carries the parent + relationship name as `public readonly` context (public, not private, to avoid PHPStan `property.onlyWritten`). `IdentifierResponse::forRelationship()` drives the relationship-document path (`requestedRelationshipName` → `transformToRelationshipObject`) to emit linkage `data` (type+id only). **Known limitation, faithful to yin:** `DocumentTransformer::transformRelationshipDocument()` does not call `transformMetaMembers()`, so top-level `meta`/`links`/`jsonapi` do not appear on relationship-endpoint bodies — `IdentifierResponse`'s `with*` document-members are therefore inert in the body. Preserved as-is (not an engine deviation); revisit if a consumer needs relationship-endpoint meta. | this phase |
| 2026-05-31 | **Public response value objects built (our design, not a yin port): `Response\{AbstractResponse, DataResponse, MetaResponse, ErrorResponse}` + `Server\ServerInterface` + concrete `@internal` documents.** Immutability via clone-then-assign (matching `AbstractRequest`); document-level members withable, payload `private readonly`; single-vs-collection via named ctors (no `is_iterable` inference). Render pipeline: `render()`→`RenderedDocument(body,status)`→`toPsrResponse()` json-encodes (inline `JSON_THROW_ON_ERROR` so PHPStan narrows to `string` — no `(string)` cast) and emits PSR-7 via the server's PSR-17 factories. **`ServerInterface` refined beyond the kickoff "minimal Server"** to also expose PSR-17 `responseFactory()`/`streamFactory()` — unavoidable to emit a PSR-7 response. Added concrete `@internal` `SingleResourceDocument`/`CollectionDocument`/`MetaDocument` (yin ships only abstract, consumer-subclassed documents; our response-VO design constructs concrete ones). `RelatedResponse`/`IdentifierResponse` deferred to the next round (relationship-document + parent context). | this phase, Phase 4.5 |
| 2026-05-30 | **Serialization-engine cluster ported as one cohesive unit** (`Transformer\*` + folded `TransformerTrait`, `Schema\Resource\*`, `Schema\Relationship\*`, `Schema\Document\*`). Transformer + Document classes are `@internal` mutable per-pass/per-request types (mirrors the `Schema\Data` decision); `Schema\Resource\*` and `Schema\Relationship\*` are consumer-facing. **Serializer-free** — transformations return arrays (JSON encoding deferred to the response layer). `ExceptionFactory` dropped from every signature (`ResourceTransformer` throws `RelationshipNotExists`/`InclusionUnrecognized` directly). Establishes the `Resources`, `Relationships (serialization-side)`, and `Serialization engine & internal documents` CLAUDE.md entries. 445 tests green. | this phase |
| 2026-05-30 | **`Utils` not ported; `AbstractSimpleResourceDocument` not ported.** `TransformerTrait` folded from yin's root `src/` into `haddowg\JsonApi\Transformer\`. `Utils::getIntegerFromQueryParam` was already inlined into the pagination parsers; its only other method `getUri` is consumed solely by the not-yet-ported Phase-2 `Schema\Pagination\*PaginationLinkProviderTrait` family — port `getUri` (into `Transformer\Utils` or a pagination-local helper) when that round lands. `AbstractSimpleResourceDocument` dropped per the recorded footgun decision. | this phase, Phase 2 |
| 2026-05-30 | **No generic on `ResourceInterface`/`AbstractResource`** (`mixed` serialized value, not `@template T of object`) — yin serializes arbitrary domain values incl. arrays, so `mixed` is honest and a `T of object` template would break the array-as-object path. `AbstractDocumentTransformation<TDocument>` does carry an invariant `@template` so its two subclasses narrow `$document` cleanly. | this phase |
| 2026-05-30 | **DEVIATION FROM YIN (reviewed, flagged): default-included relationships are detected consistently in both transform paths.** `ResourceTransformer::isIncludedRelationship()` does `array_key_exists($name, $defaultRelationships)`, i.e. expects relation names as **keys** (flipped). yin `array_flip`s in the full-resource path but passes the raw `getDefaultIncludedRelationships()` **list** (unflipped) in the relationship-document path — so yin's default-included check is effectively dead there (a latent bug). We flip in **both** paths so default-included relationships are detected uniformly. Untested edge in yin (the ported tests pass either way); type-honest at level 9. Flagged to the maintainer — revert to strict yin fidelity on request. | this phase |
| 2026-05-30 | **`lid` (JSON:API 1.1 local identifiers) implemented at the data-model level (SUPERSEDES the deferral row below).** `ResourceIdentifier` now carries `?id` + `?lid`; `fromArray()` requires `type` + at-least-one-of(`id`,`lid`), with new `ResourceIdentifierLidInvalid` and a broadened `ResourceIdentifierIdMissing` (thrown only when both are absent; detail mentions lid). `transform()` emits whichever are present. `ToManyRelationship` gains `getResourceIdentifierLids()` and its id accessor is now `list<?string>`. The request exposes `getResourceLid()`. **Hydrator impact is minimal**: relationship identifiers with `lid` flow transparently through `createRelationship()`→`fromArray()` (no logic change) and reach the relationship hydrator with `->lid` set/`->id` null (proven by `CreateHydratorTraitTest::hydratesToOneRelationshipReferencedByLid`); a `lid`-created resource still gets a server-generated id. | Maintainer chose to bring `lid` into scope now. Scoped to **accept/carry** `lid` everywhere base 1.1 allows; cross-document `lid` *resolution* (a registry mapping `lid`→created resource within one request) is left to the post-1.0 Atomic Operations extension. | this phase, post-1.0 |
| 2026-05-30 | **`lid` (JSON:API 1.1 local identifiers) is a tracked spec-compliance GAP, deferred.** yin implements no `lid` anywhere; the faithful core port therefore omits it. Per the 1.1 spec a resource object being created MAY carry `lid` instead of `id`, and a resource identifier object MUST use `lid` when referencing a not-yet-created resource — primarily exercised by the Atomic Operations extension. Recorded in `docs/spec-compliance.md`; implementing it is a deliberate enhancement beyond the port (pairs with post-1.0 atomic ops), pending maintainer direction. | this phase, post-1.0 |
| 2026-05-30 | **Negotiation validators ported (trimmed): `Negotiation\RequestValidator`/`ResponseValidator` as stateless no-arg `final class`es.** Header negotiation + JSON well-formedness only; **all JSON-schema body validation deferred** (`validateJsonApiBody`, `RequestBodyInvalidJsonApi`, `json-api-schema.json`, `justinrainbow/json-schema` excluded). `Serializer`/`ExceptionFactory`/`$includeOriginalMessageInResponse` dropped. **`AbstractMessageValidator` folded, not ported as a class** — nothing shared between request/response linting once schema validation is gone. `ResponseValidator::validateContentTypeHeader()` added (yin had none) reusing the request's profile-only media-type rule (regex currently duplicated — flag for phase-close API review). Media-type param policy stays yin-faithful **profile-only** (yin does not handle `ext`; not added, per fidelity). | Establishes the `Negotiation (validators)` CLAUDE.md entry. Schema validation is a later phase; the trimmed validators keep the negotiation entry-points stable so the later schema layer slots in without signature changes. Tests rewritten onto `nyholm/psr7`/no-arg ctors; the three `validateJsonApiBody*` tests removed (method dropped). | this phase, Phase 2/4 |
| 2026-05-30 | **Request-side pagination parsers ported as `final readonly` leaf VOs** (`Request\Pagination\*`): public promoted properties (no getters), `fromPaginationQueryParams()` named ctors, `PaginationFactory` wrapping the request. **`ExceptionFactory` dropped** — verified unused in yin's pagination (its `Utils::getIntegerFromQueryParam` silently defaults on absent/non-numeric, never throws), so the inlined `extractInt` reproduces that behaviour exactly. Link-building statics (`getPaginationQueryParams/QueryString`) retained for the Schema-side link providers. | Establishes the `Paginators (request-side)` CLAUDE.md entry. Each class carries a `// TODO(phase-2)` — they fold into a unified `Page` VO in Phase 2. Tests use `createStub(JsonApiRequestInterface)` (PHPUnit 12 flags stubs-without-expectations under `createMock`); accessor tests rewritten to public-property reads. | this phase, Phase 2 |
| 2026-05-30 | **Request layer ported (`Request\JsonApiRequestInterface`, `AbstractRequest`, `JsonApiRequest`); deliberately NOT readonly.** `AbstractRequest implements ServerRequestInterface` (interface on the base, not just the concrete class) so PSR-7 withers can return `static`; it composes + delegates to a wrapped `ServerRequestInterface`, withers `clone`-then-assign. `JsonApiRequest` lazily memoizes parsed query-param groups and invalidates on header/query-param change. **`ExceptionFactory` dropped** → direct typed throws (MediaTypeUnsupported/Unacceptable, QueryParamUnrecognized/Malformed, RequiredTopLevelMembersMissing, TopLevelMembersIncompatible, TopLevelMemberNotAllowed, RelationshipNotExists). **`Deserializer` dropped** → `getParsedBody()` prefers PSR-7 parsed body else inline `\json_decode(...JSON_THROW_ON_ERROR)` wrapped in `RequestBodyInvalidJson`. | readonly is impractical (clone-then-assign on PSR-7 withers + lazy caches); immutability holds at the use site instead. Establishes the `Requests` pattern entry in CLAUDE.md. Tests rewritten off `laminas/diactoros`+`JsonDeserializer` onto `nyholm/psr7`+`withParsedBody()` (Nyholm joins multi-value headers with `, ` — expectations updated accordingly); factory/deserializer ctor args removed; rewrites noted per the porting-workflow rule. | this phase |
| 2026-05-30 | **`Hydrator\Relationship\ToOneRelationship`/`ToManyRelationship` ported early as a Request dependency, aligned to the leaf-VO convention.** `final readonly`, public promoted properties, simple getters dropped (`getResourceIdentifier(s)()` → public `resourceIdentifier(s)`); computed helpers (`isEmpty()`, `getResourceIdentifierTypes()/Ids()`) kept. Dedicated yin tests ported too. | `JsonApiRequest::getTo{One,Many}Relationship()` returns them, so they had to land with Request. Aligned to our VO conventions now (they are public API) rather than carrying yin's getter style; the full Hydrator pattern entry is deferred to the Hydrator round, which will consume these via the public properties. | this phase |
| 2026-05-30 | **`Schema\Data\*` accumulator ported as `@internal` mutable classes (NOT readonly).** `DataInterface`/`AbstractData`/`SingleResourceData`/`CollectionData` keep yin's flat `$resources` map + by-reference `primaryKeys`/`includedKeys` design verbatim (primary precedence over included, insertion-order dedup). Modernised with typed properties, precise `array<…>` shapes, and `: static` fluent returns. | These are serialization-pass accumulators that collect resources incrementally, so the readonly VO pattern deliberately does not apply; the by-reference storage is yin's own and is behaviour-critical for included-resource dedup. Full serialization-engine pattern entry deferred to the engine round. | this phase |
| 2026-05-30 | **`ResourceIdentifier` ported as construct-only `final readonly` leaf VO; `fromArray()` throws the typed `ResourceIdentifier*` exceptions directly (no `ExceptionFactory` parameter).** Test rewritten from yin's setter/`DefaultExceptionFactory` form to the new immutable API. | First consumer of the `ResourceIdentifier*` exceptions built in the prior fan-out, hence sequenced after it. yin's `setType`/`setId`/`MetaTrait` mutators are dropped per the leaf-VO pattern; `fromArray` keeps yin's exact validation order/conditions but constructs exceptions inline since `ExceptionFactory` was never ported. Added an `@internal transform()` (resource identifier objects appear in JSON output). Test rewrite noted per the porting-workflow rule. | this phase |

## Open questions

_All kick-off open questions resolved 2026-05-30 — see decision log (Server placeholder, document namespace, absent-member convention, serialization-engine drift). New questions surfacing mid-phase are appended here and resolved interactively with the maintainer before phase close._

- ~~Response value object `Server` placeholder shape.~~ **Resolved: minimal `ServerInterface` (option a).**

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. Every yin core subsystem in scope has an equivalent under `haddowg\JsonApi\…`.
3. The exception hierarchy fully replaces `ExceptionFactory`; no `ExceptionFactory*` types exist in the package.
4. `SerializerInterface` does not exist in the package.
5. PHPStan level 9 passes with no baseline (or a baseline with explicitly justified entries).
6. Test suite passes on the full CI matrix.
7. Coverage is at or above yin's published coverage as a floor (record the floor figure in the decision log before porting begins).
8. `docs/spec-compliance.md` exists and is filled in for every section covered by this phase.
9. `CLAUDE.md` exists at the repository root with a pattern entry for every component kind listed in the Pattern documentation task; entries are accurate against the ported code.
10. A test fixture in `tests/` implements both yin's `Resource` (serializer) contract and the `Hydrator` contract directly (no inheritance from the abstract bases) and is exercised end-to-end. This proves the contracts are usable as the integration surface for the Phase 4.5 schema layer.
11. The five response value objects (`DataResponse`, `MetaResponse`, `RelatedResponse`, `IdentifierResponse`, `ErrorResponse`) exist, are immutable, expose the fluent `with…` methods listed in the task, and have unit tests covering their fluent surface plus a rendering test against a minimal `Server` placeholder/fixture.
12. Document classes (`AbstractDocument` and subclasses) are marked `@internal` (and ideally moved to an `Internal\` subnamespace). No user-facing documentation example, getting-started, or test fixture subclasses an `AbstractDocument*` directly — the documented path is "use the response value objects."
13. `AbstractSimpleResourceDocument` is **not** present in the ported codebase. The decision log records the drop with a one-line rationale.
14. The `JsonApiOperation` interface and its per-verb implementations (`FetchResourceOperation`, `CreateResourceOperation`, `UpdateResourceOperation`, `DeleteResourceOperation`, `FetchRelationshipOperation`, `FetchRelatedOperation`, `UpdateRelationshipOperation`, `AddToRelationshipOperation`, `RemoveFromRelationshipOperation`) exist as readonly value objects. The `OperationHandler` interface exists. Each per-verb operation has unit tests covering construction, accessors, and immutability.
15. `Psr7ToOperationHandlerAdapter` exists, implements PSR-15 `RequestHandlerInterface`, and has an integration test that goes PSR-7 in → operation parsed → handler called → response value object returned → PSR-7 out for at least one fetch operation and one create operation.
16. A test demonstrates programmatic dispatch: an operation is constructed directly (no PSR-7 request involved), dispatched through an `OperationHandler`, and produces the expected response value object. `OperationContext::httpRequest()` returns `null` in this test.

### Verification plan

```bash
# From a clean clone of the repo at the phase-1 head
composer install
composer test                                # full suite passes
composer phpstan                             # exits 0 at level 9
composer cs-check                            # exits 0

# Spec-group sanity: every advertised spec group has at least one test
for group in document-structure fetching-resources fetching-relationships \
             inclusion-of-related-resources sparse-fieldsets sorting \
             pagination filtering crud errors content-negotiation; do
  vendor/bin/phpunit --group "spec:$group" --list-tests \
    || echo "FAIL: spec:$group has no tests"
done

# Lowest-deps run (catches missing version constraints)
composer update --prefer-lowest --prefer-stable
composer test
```

Then verify on GitHub:

- CI matrix is green: PHP 8.3 and 8.4, lowest and highest dep strategies.
- Codecov coverage report uploaded; coverage figure recorded in the decision log.
- A tagged `0.1.0` release candidate is prepared (release-please PR present), or a tag-and-release is performed manually if maintainer prefers to defer release-please's first cut.

API surface review walkthrough:

- Read each public class/interface under `src/` and confirm: types are sound, naming is consistent, deprecated yin patterns are gone, readonly used where appropriate, enums used over magic strings/constants.
- Record any deferred cleanup items as open issues against the repo, not as blockers.

## Handover output

Before declaring the phase complete, produce the following for Phase 2:

1. **Status table update** — Phase 1 → `Complete`, Phase 2 → `Ready`.
2. **Phase 2 plan review** — `docs/phase-2-profiles-pagination.md` already exists as a pre-drafted plan. Read it end-to-end against this phase's decision log and current repository state. Confirm it still covers at minimum:
   - Profile abstraction design (interface(s), how a profile declares its URI, keywords it defines, lifecycle hooks)
   - Profile registry / discovery mechanism
   - Content negotiation integration: how `profile` media-type parameters are parsed, how supported profiles are advertised
   - Pagination refactor to consume the profile infrastructure as first consumer; each built-in paginator's associated profile URI (or decision to not associate one if no published profile exists)
   - Test plan including spec sections relevant to extensions and profiles (`spec:extensions-and-profiles`)
   - Append revisions to the plan as a single commit; the actual kick-off revision happens at the start of Phase 2, but corrections forced by Phase 1 decisions belong here.
3. **Spec compliance snapshot** — `docs/spec-compliance.md` reflects the state at end of Phase 1; any remaining gaps are flagged for the appropriate later phase.
4. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 2.
5. **Decision log finalised** — all phase-local decisions captured here; any decisions affecting future phases promoted to the cross-phase log in `PLAN.md`.
6. **Open issues filed** for any deferred clean-up identified in the API surface review.
