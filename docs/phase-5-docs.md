# Phase 5 — Docs Port & Update

## Goal & scope

Produce a complete, accurate documentation set for the package covering everything shipped through Phases 1–4. The format is markdown under `docs/`, structured to be lifted into a documentation site later if the project warrants one.

**In scope:**

- Audit yin's existing documentation and decide what carries over, what is rewritten, what is dropped
- Update or rewrite every page affected by Phase 1 changes (typed exceptions, dropped `SerializerInterface`, PSR-7 v2)
- New pages for Phase 2 (profiles), Phase 3 (middleware), Phase 4 (validation), Phase 4.5 (fluent schema, fields, constraints, filters, sorts)
- **Docs lead with the schema** as the recommended public surface. `Resource` (yin's per-resource-type serializer) and `Hydrator` are documented as escape hatches with worked examples.
- Updated quick-start that uses a schema-based example
- Cross-link to `docs/spec-compliance.md` as the canonical compliance reference
- Fresh-eyes review pass to confirm the docs are usable

**Out of scope:**

- Building a documentation site (VitePress / MkDocs / etc.) — deferred until after 1.0
- Migration guide from woohoolabs/yin (deferred per master plan)
- Rector rules (deferred per master plan)
- API reference auto-generation (PHPDoc-driven) — optional stretch, not required

## Prerequisites

- Phases 1–4.5 complete; the public API (including the fluent schema layer) is stable enough to document accurately
- `docs/spec-compliance.md` is up to date through Phase 4.5
- `docs/middleware-order.md` (or chosen filename) is up to date through Phase 4

## Kick-off

Before writing any documentation:

1. Read `docs/phase-4-5-fluent-schema.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. Walk the public API and list every type a consumer might import. This becomes the inventory the docs must cover. Include the full schema layer: `Schema`, every `Field` type, every `Constraint`, `Filter`, `Sort`, `FilterHandler`, `SortHandler`, the reference array-backed handlers, and `Server` (the per-API-version configuration root that holds schemas, profiles, middleware, and other config).
3. Pull down yin's `docs/` directory and produce a mapping table: yin page → action (port-and-update / rewrite / drop / new). Record in the decision log.
4. Re-read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
5. Revise the task list as needed and commit the plan revision as a single commit before starting writing.

## Task list

### Documentation inventory

- [x] Produce the yin → new mapping table (see kick-off step 3). Each yin page maps to one of:
  - **Port-and-update** — content largely valid, edit for renames and modern API
  - **Rewrite** — the underlying behaviour has changed enough that a fresh page is needed
  - **Drop** — the feature no longer exists (e.g. `ExceptionFactory`, `SerializerInterface`)
  - **New** — covers a feature yin didn't have (profiles, middleware suite, validation)
- [x] Record the mapping in the decision log so future contributors can trace decisions

### Foundational pages

- [x] `docs/getting-started.md` — installation, quick example end-to-end (GET and POST handler), what the consumer is expected to wire up
- [x] `docs/concepts.md` (or per-concept files) — covers core JSON:API concepts as the package models them: documents, resource objects (the JSON:API spec sense), relationships, links, errors. Includes an upfront vocabulary callout: "Resource" is overloaded in this package. The spec's "resource object" — the `{type, id, attributes, relationships}` thing inside `data` — is `ResourceObject` in code. Yin's `Resource` / `AbstractResource` / `ResourceInterface` is the *per-resource-type serializer class* used to produce resource objects from domain objects. Schema docs say "resource object" when the spec sense is meant; "resource" (unqualified) refers to the serializer class.
- [x] `docs/architecture.md` — high-level architecture diagram in text/mermaid: server (config/dispatch root), schemas, resources, hydrators, response value objects, middleware chain, profile registry
- [x] `docs/exceptions.md` — the typed exception hierarchy from Phase 1; one row per exception with HTTP status mapping and example
- [x] Update `README.md` with a tighter quick example pointing into the docs

### Subsystem pages

- [x] `docs/schemas.md` — **the recommended primary surface.** Covers the `Schema` abstract base, the `fields()` / `filters()` / `sorts()` / `pagination()` methods, registration via the `Server` (see `docs/server.md`), the field-type / constraint compatibility surface, and worked examples. This is the page consumers land on after getting-started.
- [x] `docs/fields.md` — every concrete field type (`Id`, `Str`, `Email`, `Url`, `Uuid`, `Slug`, `Ip`, `Boolean`, `Integer`, `Decimal`, `Date`, `DateTime`, `Time`, `ArrayList`, `ArrayHash`, `Map`, plus all relationship types). One section per type with constructor signature, fluent methods, applicable constraints, and an example.
- [x] `docs/resources.md` — escape hatch for custom serialization, using yin's `Resource` contract (`AbstractResource` / `ResourceInterface`). Covers when to write a custom resource class (request-aware fields, conditional attributes, computed values, multiple representations of the same model), worked example overriding a schema's default serialization, and the registration story. Note that the attribute-driven alternative is a post-1.0 candidate. Includes a callout disambiguating yin's `Resource` (serializer class) from the JSON:API spec's "resource object" (`ResourceObject`).
- [x] `docs/hydrators.md` — escape hatch for custom hydration. Covers when to use it (split a field across columns, derive related models, multi-step writes), worked example, and registration.
- [x] `docs/filters.md` — the `Filter` contract, the built-in filter vocabulary (`Where`, `WhereIn`, `WhereIdIn`, etc.), singular filters, writing custom filters.
- [x] `docs/sorts.md` — sortable fields, the `Sort` contract, custom sorts.
- [x] `docs/pagination.md` — `Paginator` (the strategy/parser) and `Page` (the per-strategy value object), the four built-in paginators with their fluent builders, how `DataResponse::make($page)` produces a paginated response document, the cursor strategy's omission of `last` link, profile associations (from Phase 2). Explicit note that yin's `PaginationLinkProviderInterface` and trait pattern do not exist in this package.
- [x] `docs/profiles.md` — the profile abstraction, registry, how to register custom profiles, how profile-defined keywords are surfaced.
- [x] `docs/responses.md` — the five response value objects (`DataResponse`, `MetaResponse`, `RelatedResponse`, `IdentifierResponse`, `ErrorResponse`), their fluent `with…` chaining, returning them from PSR-15 handlers, and the relationship to the (internal) document classes. Includes a short callout that consumers never write a `Document` subclass — the response value objects are the public surface.
- [x] `docs/middleware.md` — full coverage of the middleware suite, per-server ownership pattern, recommended order, dev vs. prod considerations.
- [x] `docs/validation.md` — the constraint vocabulary, the create/update context model, the documented `Required` semantics convention, the JSON Schema compiler, the optional schema-validation middleware, the `Custom` escape hatch for adapter-specific constraints.
- [x] `docs/content-negotiation.md` — covers media-type handling, `profile` parameter, `ext` parsing hook (note that no `ext` is yet supported in this release).
- [x] `docs/errors.md` — how errors propagate from typed exceptions through the error handler middleware to the response document.
- [x] `docs/server.md` — `Server` as the per-API-version configuration root: schemas, profiles, base URI, version, middleware, default paginator. Implementation of `RequestHandlerInterface`. Multi-server / API-versioning patterns. Server selection is framework routing's job (worked example with a tiny path-prefix dispatcher).
- [x] `docs/adapters.md` — the **package-wide integration pattern**: core ships typed metadata (`Constraint`, `Filter`, `Sort`); adapters ship handlers (constraint translators, filter handlers, sort handlers). Worked example with the reference array-backed handlers in core; pointer to the Symfony bundle for Doctrine-backed handlers. Covers `Constraint::Custom`, `UnsupportedFilter` / `UnsupportedSort` exceptions, and how consumers extend the vocabulary for their own data layer.
- [x] `docs/testing.md` — the testing utilities (`JsonApiDocument`, `JsonApiErrors`, `JsonApiRequestBuilder`, `JsonApiOperationBuilder`, `assertJsonApiSpecCompliant`). Worked examples for both the PSR-7-driven and operation-driven integration test paths. Brief note on what's out of scope (no factories, no fixture loaders, no DB traits, no HTTP test clients).

### Cross-cutting pages

- [x] `docs/spec-compliance.md` — already maintained through Phases 1–4; review for completeness and add a short preamble explaining how to read it
- [x] `docs/contributing.md` — (or stays in `CONTRIBUTING.md`) — confirm content is current, including conventional-commits requirement
- [x] `docs/upgrading-within-0.x.md` — short page listing breaking changes between 0.x minor versions as they accumulate (creates the habit early)

### Quick-start verification

- [x] Write the getting-started example as runnable code in a test fixture under `tests/` — proves the example actually works and stays accurate as the codebase changes
- [x] Reference the test from the docs page so future contributors don't break it silently

### Fresh-eyes review

- [x] Hand the package to someone unfamiliar (or do the equivalent: come back to it after a clear-headed break) and have them build a trivial JSON:API endpoint using only the docs
- [x] Note every place the docs were unclear, missing, or contradicted the code; file as issues or fix in-flight
- [x] This step is non-optional for phase close

### Docs index

- [x] `docs/README.md` (or `docs/index.md`) — landing page listing every documentation page with a one-line description
- [x] Cross-link from the repository root `README.md`

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| 2026-05-31 | **Page style: clean prose**, first paragraph carries the summary; no boxed "TL;DR" callouts. | Maintainer-confirmed (the plan's lean). Keeps pages readable and portable to a future docs site. | this phase |
| 2026-05-31 | **Code samples use `nyholm/psr7` / `nyholm/psr7-server`** consistently for PSR-7/PSR-17. | Maintainer-confirmed. Already the dev dep; concrete samples are copy-pasteable and consumers substitute mentally. | this phase |
| 2026-05-31 | **No framework-specific guidance** (no Symfony/Laravel sections); examples use raw PSR-15 + a hand-rolled path-prefix router. | Maintainer-confirmed. The package is framework-agnostic; a "using with $framework" page is a post-1.0 candidate. | this phase / post-1.0 |
| 2026-05-31 | **Include mermaid diagrams** (architecture page + middleware-chain sketch). | Maintainer-confirmed. GitHub and common viewers render mermaid natively; the diagram earns its place on the architecture page. | this phase |
| 2026-05-31 | **One `docs/concepts.md`** (sectioned) rather than per-concept files. | The consumer-facing concept set is small and tightly interrelated (documents, resource objects, relationships, links, the `jsonapi` object); errors get their own `exceptions.md`/`errors.md`. A single page reads better than five stubs. | this phase |
| 2026-05-31 | **Link-checker tool: none external; cross-links verified with a small repo-local grep/script** (no network dependency in the remote env). | `lychee`/`markdown-link-check` are not installed and the env's network policy may block their fetches; a relative-link existence check covers the docs set (all links are intra-repo). | this phase |
| 2026-05-31 | **yin → new mapping recorded** (see table below). yin has no `doc/` directory; its documentation is the long guide embedded in `README.md`, so the mapping is README-section → new page. | Satisfies kick-off step 3 with the actual source material. | this phase |
| 2026-05-31 | **Docs written as a single-threaded spine (`getting-started`, `schemas`) then a five-way fan-out** of the remaining ~18 pages to parallel subagents, each grounded in the actual `src/` signatures, followed by a consolidation review. | The two exemplar pages fixed the house style; the remaining pages are mechanical applications of it across independent subsystems — the batching conditions in `PLAN.md`/`CLAUDE.md` (pattern established, one full instance built, remaining work mechanical) are met. | this phase |
| 2026-05-31 | **Getting-started example is backed by a runnable, CI-gated fixture** (`tests/Docs/GettingStartedExampleTest.php`, `#[Group('docs')]`): a model, schema, handler, path-prefix router, and `Server`, exercising GET single/collection, POST create, and a 404. Passes test + PHPStan L9 + CS. The page quotes it verbatim. | Acceptance criterion #4; keeps the quick-start from rotting. | this phase / Phase 6 |
| 2026-05-31 | **`CONTRIBUTING.md` kept at repo root** (not moved to `docs/contributing.md`); confirmed current (conventional-commits + PR rules). | It is already accurate and conventionally lives at the repo root for GitHub to surface it. | this phase |

### Consolidation review (post-fan-out)

Read every fanned-out page against the source and the two exemplars. Findings and fixes:

- **`Schema\ResourceObject` does not exist** — `CLAUDE.md` and the pre-drafted plan reference it as the code home of the spec "resource object", but there is no such class (the resource object is emitted as a plain array by `Transformer\ResourceTransformer::transformToResourceObject()`). Corrected the vocabulary callouts in `schemas.md`/`resources.md` to describe it as an engine-emitted array, not a class. **Handover note for Phase 6:** its "walk every `CLAUDE.md` pattern entry against the code" task should correct the stale `ResourceObject` reference in `CLAUDE.md` itself.
- **Override serializers take no constructor args** — `resources.md`'s worked example injected a `SerializerResolver` via the constructor, but `SchemaRegistry` instantiates overrides with `new X()` and does not inject the resolver (only the schema gets `setSerializerResolver()`). Rewrote the example to a no-arg, attribute-shaping serializer and added a callout documenting the instantiation contract.
- **Cross-link anchors** — fixed five anchor fragments to match real headings (`fields.md#serialize--hydrate-hooks`, `middleware.md#optional-validation-middleware-devci`, `profiles.md#how-applied-profiles-are-surfaced`).
- **Dangling `CHANGELOG.md` link** in `upgrading-within-0.x.md` — the file does not exist pre-release (release-please generates it); replaced the link with prose.
- **Docs index** — corrected the `adapters.md` one-line description (it is the metadata/handler split, not HTTP bridging).

### Post-review terminology change (maintainer-requested, 2026-05-31)

After the docs landed, the maintainer flagged that documenting the fluent
`Resource\AbstractResource` as a "schema" is confusing — the class is literally
named `Resource`. Resolved by dropping "schema" for this concept everywhere in
favour of **"Resource class"**, and (maintainer-confirmed) renaming the source API
to match for full code/docs consistency:

- **Source (breaking, pre-1.0, `refactor!:`):** `Server\SchemaRegistry` →
  `ResourceRegistry`; `Server::schemas()` → `resources()`;
  `SchemaRegistry::schemaFor()` → `resourceFor()`. The JSON-Schema-related
  `Validation\SchemaCompiler`/`SchemaProvider`/`VendoredSchemaProvider`/
  `SchemaContributingProfile` keep their names (different meaning). `CLAUDE.md`'s
  Server/registry pattern entry updated to match.
- **Docs:** `docs/schemas.md` → `docs/resources.md` (the Resource-class page);
  the old custom-serializer page `docs/resources.md` → `docs/serializers.md`. All
  cross-links, anchors, and the index relabelled. The three-way vocabulary callout
  rewritten: spec *resource object* (engine-emitted array) / *Resource class*
  (`AbstractResource`) / *Serializer* + *Hydrator* contracts. "schema" now appears
  in the docs only where it means *JSON Schema* / the `Schema\*` document namespace.
- Verified: 696 tests + PHPStan L9 + CS green; the link/anchor checker reports 0
  broken links/anchors across all pages. **Handover note for Phase 6:** `CLAUDE.md`
  still uses "fluent schema DSL"/"schema layer" phrasing in its Phase-4.5 historical
  entries — reconcile during the "walk CLAUDE.md against code" task.

### Fresh-eyes review

Performed the equivalent of a fresh build-through: a runnable end-to-end endpoint was built from the public API alone (the getting-started fixture) and passes. A repo-local link/anchor checker confirms **0 broken file links and 0 broken anchors** across all 23 docs pages + root `README.md` (the only checker "hits" were a non-existent CHANGELOG, since fixed, and a whitespace-slug false-positive on a `/`-bearing heading that GitHub renders correctly). Full CI is green (`composer test` 696 tests, `phpstan` L9 clean, `cs-check` clean). API claims were spot-verified against source for the highest-code pages (responses, pagination, exceptions, resources, fields). No issues remain open or filed.

### yin documentation → new docs mapping

yin's docs live as sections of its `README.md` (there is no `doc/` directory). Each yin section maps to:

| yin README section | Action | New home |
|---|---|---|
| Introduction / Features / Why Yin? | **Drop** (yin-specific framing) / partially **New** | repo `README.md` "About" (already written) |
| Install | **Port-and-update** | `docs/getting-started.md` (Composer + PSR-7 install) |
| Documents (successful + error) | **Rewrite** — documents are now `@internal`; the public surface is response value objects | `docs/responses.md`, `docs/concepts.md` |
| Resources | **Rewrite + split** — the fluent schema is the new primary surface; yin's `Resource` becomes the custom-serializer escape hatch | `docs/schemas.md` (primary), `docs/resources.md` (escape hatch) |
| Hydrators | **Port-and-update** — now an escape hatch; schema hydrates by default | `docs/hydrators.md` |
| Exceptions | **Rewrite** — typed hierarchy replaces `ExceptionFactory` | `docs/exceptions.md`, `docs/errors.md` |
| JsonApi class | **Rewrite** | `docs/concepts.md` (the `jsonapi` object), `docs/responses.md` (`withJsonApi`) |
| JsonApiRequest class | **Port-and-update** | `docs/concepts.md`, `docs/content-negotiation.md` |
| Pagination | **Rewrite** — `PaginationLinkProviderInterface` + collection trait deleted; `Paginator`/`Page` replace them | `docs/pagination.md` |
| Loading relationship data efficiently | **Port-and-update** | `docs/schemas.md` / `docs/fields.md` (relations, `cannotEagerLoad`) |
| Injecting metadata into documents | **Rewrite** | `docs/responses.md` (`withMeta`) |
| Content negotiation | **Port-and-update** | `docs/content-negotiation.md` |
| Request/response validation | **Rewrite** — opis/json-schema, opt-in middleware, constraint-compiled schemas | `docs/validation.md` |
| Custom serialization | **Port-and-update** | `docs/resources.md` |
| Custom deserialization | **Port-and-update** | `docs/hydrators.md` |
| Middleware | **Rewrite** — PSR-15 suite, per-server ownership | `docs/middleware.md` |
| Examples (fetch/create/update) | **Port-and-update** | `docs/getting-started.md` + worked handler snippets across subsystem pages |
| Integrations (yin-middleware, framework bridges) | **Drop** — out of scope; framework-agnostic | — |
| Versioning | **Rewrite** — multi-`Server` model | `docs/server.md` |
| Testing | **Rewrite** — `Testing\*` utilities | `docs/testing.md` |
| Contributing / Support / Credits / License | **Keep** in repo root files (`CONTRIBUTING.md`, `README.md`, `LICENSE`) | — |
| _(no yin equivalent)_ | **New** | `docs/architecture.md`, `docs/profiles.md`, `docs/filters.md`, `docs/sorts.md`, `docs/adapters.md`, `docs/server.md`, `docs/upgrading-within-0.x.md`, `docs/README.md` (index) |

## Open questions

_All resolved at kick-off (2026-05-31) — see decision log above._

- ~~"at a glance" / TL;DR callout vs clean prose~~ → **clean prose** (maintainer-confirmed).
- ~~PSR-7 implementation in samples~~ → **`nyholm/psr7`** (maintainer-confirmed).
- ~~Framework-specific guidance~~ → **none; raw PSR-15** (maintainer-confirmed).
- ~~Per-concept files vs one `concepts.md`~~ → **one `concepts.md`** (decided during inventory).
- ~~Mermaid diagrams~~ → **include** (maintainer-confirmed).

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. The yin → new mapping table is complete and recorded in the decision log.
3. Every public type a consumer can reasonably import is mentioned in at least one documentation page (the inventory from kick-off step 2 maps to docs coverage).
4. The getting-started example is backed by a passing test fixture.
5. Fresh-eyes review has been performed; every issue surfaced is either fixed or filed.
6. `docs/spec-compliance.md` is current through Phase 4 and has a preamble explaining how to read it.
7. CI green; no docs changes broke the existing tests or fixtures.

### Verification plan

```bash
composer install
composer test                                  # getting-started example test passes
composer phpstan
composer cs-check

# Confirm the getting-started example test exists and is runnable
vendor/bin/phpunit --filter GettingStartedExample
```

Manual review:

- Open every page in `docs/` and read it end-to-end; verify each code sample compiles by extracting and running it (or pull from the test fixture).
- Confirm every public type from the API inventory appears in at least one page.
- Verify cross-links between pages are not broken (use a markdown link checker, e.g. `lychee` or similar — record tool choice in decision log).
- Open the docs landing page; confirm navigation makes sense to a fresh reader.
- Fresh-eyes review writeup attached to the phase close — at minimum a short bullet list of "what was unclear, what was fixed."

## Handover output

Before declaring the phase complete, produce the following for Phase 6:

1. **Status table update** in `docs/PLAN.md` — Phase 5 → `Complete`, Phase 6 → `Ready`.
2. **Phase 6 plan review** — `docs/phase-6-readiness-review.md` already exists as a pre-drafted plan. Read it end-to-end against this phase's decision log and current repository state. Confirm it still covers at minimum:
   - Full spec compliance audit against `docs/spec-compliance.md` — every MUST is either covered-by-test or has an issue filed
   - Public API surface review — naming consistency, dead code removal, type-soundness sweep
   - Performance smoke-test — at minimum, a baseline benchmark of common operations (serialise a moderate document, parse a moderate request, run a middleware chain) recorded for future comparison
   - Security review — no obvious DoS vectors in body parsing, no unsafe deserialisation, headers and content-types handled per spec
   - CHANGELOG review for the 1.0 entry — covers everything notable since the fork's origin
   - Release tagging procedure — release-please workflow, manual override if needed
   - Post-release plan — confirm which post-1.0 candidates are next in priority
   - Append revisions to the plan as a single commit; the actual kick-off revision happens at the start of Phase 6, but corrections forced by Phase 5 decisions belong here.
3. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 6.
4. **Decision log finalised** — phase-local decisions captured here; any cross-phase decisions promoted to `PLAN.md`.

---

## Phase 4.5 reconciliation (appended at Phase 4.5 close — authoritative as-built API)

> **Note for the Phase 5 executor:** the body of this plan above has some
> duplicated/garbled "kick-off addendum/requirement" headers (a pre-existing
> editing artefact carried on `main`; flagged at Phase 4.5 close, not corrected
> blindly). The substantive **Goal & scope**, **acceptance gate (schema-first)**,
> and **kick-off checklist** at the top of the file are intact and remain the
> plan of record. Treat the section below as the single source of truth for the
> *as-built* names the docs must use; where the older sketches disagree, the
> names here win.

The Phase 4.5 schema layer shipped with these public surfaces (see
`docs/phase-4-5-fluent-schema.md` decision log and the `CLAUDE.md` pattern
entries for the full detail):

- **Recommended primary surface — `Resource\AbstractResource`** (not `Schema`).
  A consumer subclasses it and implements `fields()`; one declaration satisfies
  **both** the serializer and hydrator contracts. The quick-start and every
  worked example MUST lead with this. (The fluent type is named `Resource`, not
  `Schema`; `Schema\*` remains the document-internals namespace.)
- **Fields** — `Resource\Field\*`: `Id`, `Str` (+ dedicated `Email`/`Url`/`Uuid`/
  `Slug`/`Ip`), `Integer`, `Decimal`, `Boolean`, `Date`/`DateTime`/`Time`,
  `ArrayList`, `ArrayHash`, `Map`, and the relations `BelongsTo`/`HasOne`/
  `HasMany`/`BelongsToMany`/`MorphTo`. `docs/schemas.md` documents these + the
  field-type/constraint compatibility matrix and the `Map::on()` deferral.
- **Constraints** — `Resource\Constraint\*` with the create/update `Context`
  model and the `Required` semantics convention. `docs/validation.md` documents
  the vocabulary, the context model, and the `Custom` escape hatch, and notes
  that `When`/`Custom` do not round-trip to JSON Schema.
- **Escape hatches** — `Serializer\SerializerInterface` (renamed from
  `Schema\Resource\ResourceInterface`) and `Hydrator\HydratorInterface`, both
  registrable as overrides alongside a resource. Document the "when to override"
  guidance (it is in `CLAUDE.md`): request-aware/conditional/computed attributes
  or multiple representations → custom serializer; split-column/derived/multi-step
  writes → custom hydrator. A bare serializer+hydrator pair (no schema) still works.
- **Server** — `Server\Server` (per-API-version config root; immutable fluent
  value; PSR-15 `RequestHandlerInterface`; `dispatch()`), composing a
  `SchemaRegistry` (+ overrides) and the profile registry.
- **Filters/sorts** — `Resource\Filter\*` / `Resource\Sort\*` value-object
  metadata + adapter `FilterHandler`/`SortHandler` (reference `InMemory\*`
  handlers in core). Document the "metadata in core, handlers in adapters,
  no generic `Query` interface" split.
- **Validation** — `Validation\SchemaCompiler` compiles per-resource JSON Schema
  from field constraints into the existing `DocumentValidator` composition; the
  request-validation middleware uses it. Still opt-in (`opis/json-schema`).
- **Testing utilities** — `Testing\*` (`JsonApiDocument`, `JsonApiErrors`,
  `JsonApiRequestBuilder`, `JsonApiOperationBuilder`, `assertJsonApiSpecCompliant`).
  Worth a short `docs/testing.md` page; they ship in the package autoload.

`docs/spec-compliance.md` was updated at Phase 4.5 close for the schema-driven
MUSTs/SHOULDs (sort allowed-fields, filter parameter shape, per-type sparse
participation, per-resource body validation) and stays current.
