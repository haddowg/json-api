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

- [ ] Produce the yin → new mapping table (see kick-off step 3). Each yin page maps to one of:
  - **Port-and-update** — content largely valid, edit for renames and modern API
  - **Rewrite** — the underlying behaviour has changed enough that a fresh page is needed
  - **Drop** — the feature no longer exists (e.g. `ExceptionFactory`, `SerializerInterface`)
  - **New** — covers a feature yin didn't have (profiles, middleware suite, validation)
- [ ] Record the mapping in the decision log so future contributors can trace decisions

### Foundational pages

- [ ] `docs/getting-started.md` — installation, quick example end-to-end (GET and POST handler), what the consumer is expected to wire up
- [ ] `docs/concepts.md` (or per-concept files) — covers core JSON:API concepts as the package models them: documents, resource objects (the JSON:API spec sense), relationships, links, errors. Includes an upfront vocabulary callout: "Resource" is overloaded in this package. The spec's "resource object" — the `{type, id, attributes, relationships}` thing inside `data` — is `ResourceObject` in code. Yin's `Resource` / `AbstractResource` / `ResourceInterface` is the *per-resource-type serializer class* used to produce resource objects from domain objects. Schema docs say "resource object" when the spec sense is meant; "resource" (unqualified) refers to the serializer class.
- [ ] `docs/architecture.md` — high-level architecture diagram in text/mermaid: server (config/dispatch root), schemas, resources, hydrators, response value objects, middleware chain, profile registry
- [ ] `docs/exceptions.md` — the typed exception hierarchy from Phase 1; one row per exception with HTTP status mapping and example
- [ ] Update `README.md` with a tighter quick example pointing into the docs

### Subsystem pages

- [ ] `docs/schemas.md` — **the recommended primary surface.** Covers the `Schema` abstract base, the `fields()` / `filters()` / `sorts()` / `pagination()` methods, registration via the `Server` (see `docs/server.md`), the field-type / constraint compatibility surface, and worked examples. This is the page consumers land on after getting-started.
- [ ] `docs/fields.md` — every concrete field type (`Id`, `Str`, `Email`, `Url`, `Uuid`, `Slug`, `Ip`, `Boolean`, `Integer`, `Decimal`, `Date`, `DateTime`, `Time`, `ArrayList`, `ArrayHash`, `Map`, plus all relationship types). One section per type with constructor signature, fluent methods, applicable constraints, and an example.
- [ ] `docs/resources.md` — escape hatch for custom serialization, using yin's `Resource` contract (`AbstractResource` / `ResourceInterface`). Covers when to write a custom resource class (request-aware fields, conditional attributes, computed values, multiple representations of the same model), worked example overriding a schema's default serialization, and the registration story. Note that the attribute-driven alternative is a post-1.0 candidate. Includes a callout disambiguating yin's `Resource` (serializer class) from the JSON:API spec's "resource object" (`ResourceObject`).
- [ ] `docs/hydrators.md` — escape hatch for custom hydration. Covers when to use it (split a field across columns, derive related models, multi-step writes), worked example, and registration.
- [ ] `docs/filters.md` — the `Filter` contract, the built-in filter vocabulary (`Where`, `WhereIn`, `WhereIdIn`, etc.), singular filters, writing custom filters.
- [ ] `docs/sorts.md` — sortable fields, the `Sort` contract, custom sorts.
- [ ] `docs/pagination.md` — `Paginator` (the strategy/parser) and `Page` (the per-strategy value object), the four built-in paginators with their fluent builders, how `DataResponse::make($page)` produces a paginated response document, the cursor strategy's omission of `last` link, profile associations (from Phase 2). Explicit note that yin's `PaginationLinkProviderInterface` and trait pattern do not exist in this package.
- [ ] `docs/profiles.md` — the profile abstraction, registry, how to register custom profiles, how profile-defined keywords are surfaced.
- [ ] `docs/responses.md` — the five response value objects (`DataResponse`, `MetaResponse`, `RelatedResponse`, `IdentifierResponse`, `ErrorResponse`), their fluent `with…` chaining, returning them from PSR-15 handlers, and the relationship to the (internal) document classes. Includes a short callout that consumers never write a `Document` subclass — the response value objects are the public surface.
- [ ] `docs/middleware.md` — full coverage of the middleware suite, per-server ownership pattern, recommended order, dev vs. prod considerations.
- [ ] `docs/validation.md` — the constraint vocabulary, the create/update context model, the documented `Required` semantics convention, the JSON Schema compiler, the optional schema-validation middleware, the `Custom` escape hatch for adapter-specific constraints.
- [ ] `docs/content-negotiation.md` — covers media-type handling, `profile` parameter, `ext` parsing hook (note that no `ext` is yet supported in this release).
- [ ] `docs/errors.md` — how errors propagate from typed exceptions through the error handler middleware to the response document.
- [ ] `docs/server.md` — `Server` as the per-API-version configuration root: schemas, profiles, base URI, version, middleware, default paginator. Implementation of `RequestHandlerInterface`. Multi-server / API-versioning patterns. Server selection is framework routing's job (worked example with a tiny path-prefix dispatcher).
- [ ] `docs/adapters.md` — the **package-wide integration pattern**: core ships typed metadata (`Constraint`, `Filter`, `Sort`); adapters ship handlers (constraint translators, filter handlers, sort handlers). Worked example with the reference array-backed handlers in core; pointer to the Symfony bundle for Doctrine-backed handlers. Covers `Constraint::Custom`, `UnsupportedFilter` / `UnsupportedSort` exceptions, and how consumers extend the vocabulary for their own data layer.
- [ ] `docs/testing.md` — the testing utilities (`JsonApiDocument`, `JsonApiErrors`, `JsonApiRequestBuilder`, `JsonApiOperationBuilder`, `assertJsonApiSpecCompliant`). Worked examples for both the PSR-7-driven and operation-driven integration test paths. Brief note on what's out of scope (no factories, no fixture loaders, no DB traits, no HTTP test clients).

### Cross-cutting pages

- [ ] `docs/spec-compliance.md` — already maintained through Phases 1–4; review for completeness and add a short preamble explaining how to read it
- [ ] `docs/contributing.md` — (or stays in `CONTRIBUTING.md`) — confirm content is current, including conventional-commits requirement
- [ ] `docs/upgrading-within-0.x.md` — short page listing breaking changes between 0.x minor versions as they accumulate (creates the habit early)

### Quick-start verification

- [ ] Write the getting-started example as runnable code in a test fixture under `tests/` — proves the example actually works and stays accurate as the codebase changes
- [ ] Reference the test from the docs page so future contributors don't break it silently

### Fresh-eyes review

- [ ] Hand the package to someone unfamiliar (or do the equivalent: come back to it after a clear-headed break) and have them build a trivial JSON:API endpoint using only the docs
- [ ] Note every place the docs were unclear, missing, or contradicted the code; file as issues or fix in-flight
- [ ] This step is non-optional for phase close

### Docs index

- [ ] `docs/README.md` (or `docs/index.md`) — landing page listing every documentation page with a one-line description
- [ ] Cross-link from the repository root `README.md`

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| _yyyy-mm-dd_ | _(example: drop yin's `events` documentation page — no equivalent feature in the package)_ | _(rationale)_ | _(this phase / future phases)_ |

## Open questions

- Should each page have an "at a glance" or "TL;DR" callout, or just clean prose? Lean: clean prose, with the first paragraph carrying the summary.
- Should examples use a specific PSR-7 implementation (`nyholm/psr7`) in their code samples, or keep them implementation-agnostic with placeholder factory calls? Lean: use `nyholm/psr7` consistently — it's the dev dep already and consumers can substitute mentally.
- Should the documentation include any framework-specific guidance (Symfony HttpKernel adapter, Laravel routing notes)? Lean: no — the package is framework-agnostic, examples use raw PSR-15. A "using with $framework" section is a post-1.0 candidate.
- Per-concept file structure vs. one big `concepts.md`? Decide during inventory; lean toward per-concept once concept count is known.
- Mermaid diagrams: include or skip? Adds value for the architecture page but introduces a rendering dependency for any future docs site. Lean: include — markdown viewers and GitHub render mermaid natively.

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
