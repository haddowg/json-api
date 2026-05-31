# Phase 2 — Profiles Infrastructure & Pagination

## Goal & scope

Introduce a general-purpose JSON:API profile infrastructure into the core, and refactor the built-in paginators to be its first consumers. By the end of this phase the package can advertise, negotiate, and apply profiles correctly per the JSON:API 1.1 specification, and consumer code can register custom profiles without library changes.

**In scope:**

- Profile abstraction: a contract that any profile (built-in or consumer-supplied) implements
- Profile registry: how profiles are registered, looked up, and discovered
- Content-negotiation integration: parsing and emitting the `profile` media-type parameter; advertising supported profiles
- Document-level profile linkage: emitting `links.profile` when profiles are in effect
- **Pagination rewrite:** split into `Paginator` (request-parser + strategy config) and `Page` (per-strategy value object carrying paginated items + link-emission metadata). Replaces yin's `PaginationLinkProviderInterface` + collection-side trait pattern (not ported). Paginators integrate with the profile infrastructure where a published profile URI exists.
- Tests tagged with relevant spec sections (notably `spec:extensions-and-profiles` and `spec:content-negotiation`)

**Out of scope:**

- Atomic Operations extension (post-1.0 candidate; it's an `ext`, not a `profile`, but uses the same negotiation layer — design the negotiation parsing to handle both)
- Schema validation of profile-defined keywords (Phase 4)
- PSR-15 middleware that integrates profiles with the request lifecycle (Phase 3)
- Attribute-driven profile declarations on resources (post-1.0 candidate)
- Authoring or publishing custom profiles for `haddowg` — only the infrastructure

## Prerequisites

- Phase 1 complete: core ported, modernised, exception hierarchy in place
- Pagination implementations exist and work (ported in Phase 1)
- Content-negotiation logic exists (ported in Phase 1) and is the integration point for profile handling

## Kick-off

Before writing any implementation code:

1. Read `docs/phase-1-core-port.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. Re-read the [JSON:API 1.1 Extensions and Profiles section](https://jsonapi.org/format/1.1/#extensions) end-to-end. The plan below is grounded in that section; if any divergence is found, fix the plan first.
3. Re-read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
4. Survey the JSON:API ecosystem for actually-published profile URIs (e.g. cursor-pagination, atomic-operations is separate as it's an `ext`). Record the inventory in the decision log so it can inform paginator wiring.
5. Revise the task list as needed and commit the plan revision as a single commit before starting implementation.

## Task list

### Profile abstraction

- [ ] Define `haddowg\JsonApi\Schema\Profile\ProfileInterface` with:
  - `uri(): string` — the profile's canonical URI (the value matched against the negotiated `profile` parameter).
  - A mechanism to declare any reserved keywords the profile contributes (e.g. attribute names, link relations, query parameters). **Decided at kick-off:** a `keywords(): array<string>` declaration (the reserved member/link/query-param names), used for documentation/introspection and future schema validation; it does not gate negotiation.
  - **A document-finalisation lifecycle hook** (kick-off decision: *allow* a hook). Working signature: `finalizeDocument(array $document, JsonApiRequestInterface $request): array` — given the assembled document body array and the active request, return the (possibly augmented) body. Default no-op in the base class. Invoked by the response render path once per applied profile, after the document body is built and before JSON encoding. Profiles stay advisory: the hook only runs for profiles the server has applied.
- [ ] Define `AbstractProfile` base class to reduce boilerplate for profile authors (default `keywords()` → `[]`, default `finalizeDocument()` → identity).
- [ ] Add value object(s) for parsed profile media-type parameters (see Content-negotiation integration — the `MediaTypeParameters`/`ext`+`profile` parse result).

### Profile registry

- [ ] `haddowg\JsonApi\Schema\Profile\ProfileRegistry` — register, look up by URI, list all. **Decided at kick-off:** a simple map keyed by profile URI (no content-type-style quality-factor negotiation — the spec does not require negotiation across profiles). Eager resolution (profiles are cheap value objects). Registering the same URI twice throws a typed `ProfileAlreadyRegistered` (configuration error, surfaced at wiring time, not request time).
- [ ] **Decided at kick-off:** registry scope is **per-instance, injected** (no global static), avoiding global state and matching the per-`Server` configuration-root direction.
- [ ] Lookup `has(string $uri): bool` / `get(string $uri): ?ProfileInterface` / `all(): list<ProfileInterface>`.
- [ ] **Forward-compatibility note.** Phase 4.5 introduces a broader resource-type `Registry` that holds schemas, profiles, and optional resource/hydrator overrides. The profile registry shipped here is likely to fold into that broader registry. Design the public API of this registry with that future merge in mind: avoid naming choices or signatures that would force a breaking rename later. Record the chosen naming in the decision log.
- [ ] Unit tests for registration, conflict handling (same URI registered twice), and lookup

### Content-negotiation integration

> **Spec-grounding correction (kick-off).** The pre-drafted plan said unsupported *profiles* must be rejected with `406`. That is wrong: JSON:API 1.1 states **"A server MUST ignore any profiles that it does not recognize"** — profiles are advisory. The `406`/`415` rejection rules apply to **extensions (`ext`)**, not profiles. The tasks below are corrected accordingly (maintainer-confirmed; see decision log).

- [ ] Profile parsing on the request already exists from Phase 1 (`JsonApiRequest::getRequestedProfiles()` ← `Accept`, `getRequiredProfiles()` ← `profile` query param, `getAppliedProfiles()` ← `Content-Type`). Confirm it conforms to the JSON:API 1.1 rules (space-separated URI list) and reconcile with the registry; extend only where gaps exist.
- [ ] **Generalise the media-type parser to also read `ext`** (space-separated URI list, same shape as `profile`). Add `getRequestedExtensions()` / `getAppliedExtensions()` request accessors mirroring the profile ones. `ext` is parsed and exposed but not wired downstream — it is the hook a future Atomic Operations implementation plugs into.
- [ ] **`MediaType::isValid()` must accept both `ext` and `profile` parameters** (currently profile-only). Update the single-source-of-truth rule so a media type carrying `ext` is no longer treated as invalid.
- [ ] **Extension negotiation (typed exceptions), profiles advisory:**
  - `Content-Type` carrying an **unsupported `ext`** URI → `415` (extend/throw via the existing `MediaTypeUnsupported`, or a dedicated typed exception if the message/detail differs).
  - `Accept` where every JSON:API media-type instance is unusable or carries an **unsupported `ext`** → `406` (`MediaTypeUnacceptable`).
  - Unrecognized **profiles are ignored**, never rejected. No 406/415 for profiles.
  - Note: with **no extensions supported by the server in this phase**, any `ext` present is "unsupported" — so the rules above are wired and tested against the empty supported-ext set, ready for atomic-ops to register an `ext` later.
- [ ] Apply (echo back) **applied profiles** on the response `Content-Type` `profile` parameter when the server has applied them, and emit the `Vary: Accept` header (spec SHOULD for servers supporting `ext`/`profile`). The set of applied profiles is the intersection of the request's requested/required profiles with the server's registered profiles, plus any profiles a paginator/response activates.
- [ ] Tests covering: requesting a supported profile (applied + echoed), requesting an **unrecognized profile (ignored, no error)**, no profile, multiple profiles, malformed `profile` parameter, an `ext` present with no server support (→ 415 on Content-Type / 406 on Accept), and the `Vary` header.

### Document-level integration

- [ ] When a profile is applied to a response, the document's top-level `links.profile` member should reflect the profile URI(s) per the spec. `DocumentLinks` already carries a `profiles` list and emits `links.profile` (Phase 1); add the wiring so the response render path populates it from the applied-profile set + any `ProfileLinkObject` aliases, without each document class needing to know.
- [ ] Run each applied profile's `finalizeDocument()` hook over the assembled body during render (after the body array is built, before encoding), in registration order.
- [ ] Tests asserting `links.profile` emission and that the `finalizeDocument()` hook runs and can augment the body.

### Pagination refactor — `Paginator` + `Page` split

This is a structural rewrite of yin's pagination, not a modernisation. The intent and request-parsing logic stay; the link-emission machinery is replaced.

- [ ] Yin's `PaginationLinkProviderInterface`, `PageBasedPaginationLinkProviderTrait`, `OffsetBasedPaginationLinkProviderTrait`, `CursorBasedPaginationLinkProviderTrait`, and `FixedPageBasedPaginationLinkProviderTrait` are **not ported**. The collection-side trait pattern is replaced wholesale. Record this in the decision log with a one-line rationale.
- [ ] `DocumentLinks::setPagination($uri, $linkProvider)` is **not ported** in its current form. Pagination link generation moves to the `Page` value objects below.
- [ ] **`Paginator` (renamed from yin's `Pagination`) — naming locked in at kick-off (maintainer-confirmed).** Strategy classes that read `page[...]` query params and hold defaults. One per pagination strategy: `PagePaginator`, `OffsetPaginator`, `CursorPaginator`, `FixedPagePaginator`. Fluent: `make()` static factory plus `withPageKey('number')`, `withPerPageKey('size')`, `withDefaultPerPage(15)`, etc. (vocabulary borrowed from Laravel JSON:API; finalise during implementation). Live under `haddowg\JsonApi\Pagination\*` (decide exact namespace at port time; record in decision log).
- [ ] **`Page` value objects.** Strategy-specific, immutable, carry the paginated items + the metadata the strategy needs to emit links and meta:
  - `PageBasedPage` — totalItems, currentPage, size; emits `first`/`prev`/`next`/`last` based on `ceil(totalItems / size)`. No profile (none published).
  - `OffsetBasedPage` — totalItems, offset, limit; emits `first`/`prev`/`next`/`last`. No profile (none published).
  - `CursorBasedPage` — limit, cursorBefore, cursorAfter, hasMore (no totalItems by design); emits `first`/`prev`/`next` only — **`last` is intentionally omitted** because computing it would defeat the cursor's purpose. Document this explicitly. **Associated with the published cursor-pagination profile** (see below).
  - `FixedPagePage` — currentPage, totalItems (or hasMore for simple pagination); same emission shape. No profile.
- [ ] `Page` is iterable (implements `IteratorAggregate`) so the response-side iteration over the paginated items "just works" without unwrapping.
- [ ] `Page` exposes a link-emission method (working signature: `linkSet(string $baseUri, array $queryParams): array<string, Link|null>` returning the `first`/`prev`/`next`/`last` map) and a meta-emission method (`pageMeta(): array` returning the contents of `meta.page.{...}`). Both invoked by `DataResponse` rendering (the rendering signatures stabilise during implementation).
- [ ] `Paginator::paginate($queryResult, ...): Page` produces the right `Page` subtype from a query result plus the request's pagination params. The contract is small; consumers/adapters can implement custom paginators by implementing `Paginator` and returning whatever `Page` subtype is appropriate.
- [ ] **No collection-side concerns.** Collections passed to `DataResponse::make($collection)` are plain iterables — `array`, `Doctrine\Common\Collections\Collection`, generators — and never implement a pagination interface or use a pagination trait. The paginated path is `DataResponse::make($page)` where `$page` is a `Page` instance. Coordinate with the Phase 1 `DataResponse` design.

### Pagination profile association

> **Kick-off survey result (maintainer-confirmed):** the only published pagination profile is **`http://jsonapi.org/profiles/ethanresnick/cursor-pagination/`** (defines `page[size]`, `page[after]`, `page[before]`; `prev`/`next` required, `first`/`last` recommended-when-cheap; meta members under `page`: `cursor`, `total`, `estimatedTotal.bestGuess`, `rangeTruncated`). Page-based and offset-based have **no** published profile → no association.

- [ ] **Align the cursor paginator to the published profile (maintainer-confirmed).** `CursorPaginator` reads `page[after]` / `page[before]` / `page[size]` (a deliberate pre-1.0 break from yin's single `page[cursor]`), and `CursorBasedPage` carries `cursorBefore` / `cursorAfter` / `limit` / `hasMore`. `CursorBasedPage` is associated with the cursor-pagination profile URI and advertises it on the response.
- [ ] **Profile-defined query parameters.** The cursor profile reserves `page[after]`/`page[before]`/`page[size]`; these are surfaced to consumers through the `CursorPaginator`/`CursorBasedPage` accessors (not a generic profile-query-param API). The general design space (a profile contributing arbitrary query params consumable by user code) is flagged in the decision log as out of scope until a non-pagination profile needs it.
- [ ] **Profile-association mechanism.** A `Page` exposes `profile(): ?ProfileInterface` (method-on-base, default `null`); only `CursorBasedPage` returns the cursor profile. Avoids a separate `ProfileAwarePaginator` sub-interface and its `instanceof` at the render site. The `Page` carries the profile through to rendering, which adds it to the applied-profile set (→ echoed `Content-Type` + `links.profile`).
- [ ] Update existing paginator tests to assert profile behaviour where applicable; add new tests for the profile-aware path; add tests for the fluent builders; add tests for each `Page` subtype's link emission against fixtures (including cursor's omission of `last`).

### Public API & ergonomics review

- [ ] Walk the additions made in this phase; confirm naming is consistent with Phase 1 conventions
- [ ] Confirm a consumer can register a custom profile in a small amount of code; if it feels awkward, fix it before phase close
- [ ] Update `docs/spec-compliance.md` rows for extensions/profiles and content negotiation

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| 2026-05-31 (kick-off) | **Spec-grounding correction: unrecognized profiles are IGNORED, never `406`.** The pre-drafted plan's "reject unsupported profiles with 406" task, acceptance criterion 4, and manual-verification step were rewritten. `406`/`415` are reserved for the **`ext`** parameter (415 = unsupported `ext` on Content-Type; 406 = every Accept instance unusable or carrying an unsupported `ext`). | JSON:API 1.1: *"A server MUST ignore any profiles that it does not recognize."* Profiles are advisory; only extensions demand strict client/server agreement. Maintainer confirmed "fix plan to match spec". | this phase, Phase 3, post-1.0 atomic ops |
| 2026-05-31 (kick-off) | **`ProfileInterface` gets a document-finalisation hook** (`finalizeDocument(array $body, JsonApiRequestInterface): array`), default no-op in `AbstractProfile`. Runs once per applied profile during render, after the body array is built, before encoding. | Maintainer chose "allow a finalisation hook" over strict-declarative. The spec permits a profile to define document members; a hook lets a profile inject/transform them at a single well-defined point while staying advisory (only applied profiles run). | this phase, Phase 4.5 |
| 2026-05-31 (kick-off) | **Cursor paginator aligns to the published `ethanresnick/cursor-pagination` profile**: `page[after]`/`page[before]`/`page[size]` (pre-1.0 break from yin's `page[cursor]`); `CursorBasedPage` carries `cursorBefore`/`cursorAfter`/`limit`/`hasMore`, omits `last`, and advertises `http://jsonapi.org/profiles/ethanresnick/cursor-pagination/`. Page-based/offset-based/fixed get **no** profile (none published). | Maintainer chose "align to published profile". Makes the cursor paginator the end-to-end profile consumer the phase needs, and ships a spec-faithful cursor strategy rather than yin's ad-hoc single cursor. | this phase |
| 2026-05-31 (kick-off) | **`Paginator` naming locked in**: `PagePaginator`/`OffsetPaginator`/`CursorPaginator`/`FixedPagePaginator` (strategies) + `*Page` value objects, replacing yin's `Pagination`. | Maintainer confirmed; matches the noun-for-the-thing pattern (`Schema`, `Field`, `Filter`). | this phase |
| 2026-05-31 (kick-off) | **Profile registry = per-instance, injected, simple map keyed by URI**; eager; duplicate-URI registration throws `ProfileAlreadyRegistered`. No quality-factor negotiation. | Recommendations in the plan were unambiguous and low-stakes; forward-compatible with the Phase 4.5 `Server`-owned registry merge. Spec requires no cross-profile negotiation. | this phase, Phase 4.5 |
| 2026-05-31 (kick-off) | **`Page` exposes `profile(): ?ProfileInterface` (method-on-base)**, not a `ProfileAwarePaginator` sub-interface. | Avoids an `instanceof` at the render site; only `CursorBasedPage` overrides to return the profile. | this phase |
| 2026-05-31 (kick-off) | **Profile-defined query parameters are surfaced through the owning paginator's accessors**, not a generic profile-query-param API. A general "profile contributes consumable query params" mechanism is **out of scope** until a non-pagination profile needs it. | Only the cursor profile defines query params this phase, and they are naturally the cursor paginator's own inputs. Avoids speculative generality. | this phase, post-1.0 |
| 2026-05-31 (kick-off) | **`ext` parsing implemented this phase (parse + negotiate 415/406 against the empty supported-ext set); no dispatch.** `MediaType::isValid()` updated to accept both `ext` and `profile`. Request gains `getRequestedExtensions()`/`getAppliedExtensions()`. | Lets the post-1.0 Atomic Operations effort register an `ext` and have negotiation already correct. | this phase, post-1.0 atomic ops |
| 2026-05-31 (kick-off) | **Published-profile inventory:** only `http://jsonapi.org/profiles/ethanresnick/cursor-pagination/` is relevant (cursor pagination). No published page/offset profile. | Ecosystem survey during kick-off (jsonapi.org profile registry + cursor-pagination profile doc). | this phase |
| 2026-05-31 | **Profile abstraction + registry built** (`Schema\Profile\{ProfileInterface,AbstractProfile,ProfileRegistry,ProfileAlreadyRegistered}`). `ProfileInterface` = `uri()` + `keywords(): list<string>` + `finalizeDocument(array,$request): array`. Registry is a per-instance eager URI map; duplicate registration throws `ProfileAlreadyRegistered` (a `\LogicException`, **not** a `JsonApiException` — a wiring bug must never render as an error document). Reached via `ServerInterface::profiles()`. | Smallest contract that satisfies the maintainer's finalisation-hook choice; forward-compatible with the Phase-4.5 `Server` registry merge. | this phase, Phase 4.5 |
| 2026-05-31 | **`ext` negotiation implemented (parse + 415/406), no dispatch.** `MediaType::isValid()` now accepts `ext`+`profile`; added `MediaType::split()` (quote-aware: a comma inside a quoted param value no longer fragments a multi-instance header). Request gains `getRequestedExtensions()`/`getAppliedExtensions()`. `RequestValidator(string ...$supportedExtensions)` throws `MediaTypeUnsupported` (415, Content-Type) / `MediaTypeUnacceptable` (406, Accept) for an `ext` outside its supported set (empty by default). **Test impact:** three Phase-1 tests that asserted request-level 415/406 for a *well-formed* `ext` were rewritten — a bare `ext` is now well-formed at `validate*Header()` level (param-name check), and support is a separate `RequestValidator` concern; `ResponseValidator` now treats `ext` as a valid response Content-Type param. | Maintainer chose to wire `ext` now for the post-1.0 atomic-ops drop-in. The split-on-unquoted-comma fix was forced by `ext="a,b"`-style quoted values. | this phase, post-1.0 atomic ops |
| 2026-05-31 | **Profiles are advisory in negotiation (spec-correct).** `RequestValidator` never rejects on a profile; unrecognized profiles are silently ignored end-to-end. Only `ext` triggers 415/406. | JSON:API 1.1: "a server MUST ignore any profiles that it does not recognize." | this phase |
| 2026-05-31 | **Profile *application* lives in the response layer**, not on the profile/registry. `Response\AbstractResponse::appliedProfiles()` = (request requested ∪ required) ∩ server-registered; `toPsrResponse()` runs each applied profile's `finalizeDocument()`, writes `links.profile`, echoes the `Content-Type` `profile` param, and sets `Vary: Accept`. `DataResponse` overrides `appliedProfiles()` to prepend a paginated `Page`'s profile. | Keeps the profile contract declarative and the application logic in one place the render path already owns. The cursor paginator is the end-to-end consumer (acceptance criterion 2). | this phase, Phase 3 |
| 2026-05-31 | **A `Page`'s own profile is gated on server registration** (refinement). `DataResponse::appliedProfiles()` only advertises `$page->profile()` when `server->profiles()->get($uri)` recognises it, and applies the **registered** instance (so server configuration of that profile wins). An unregistered page profile is silently dropped — pagination links/meta still emit. | Uniform rule: a response never advertises a profile the server has not registered, whether the URI came from the request *or* from a paginator. Avoids a cursor response asserting a profile the server never opted into. Tested both ways (`DataResponsePaginationTest`). | this phase, Phase 4.5 |
| 2026-05-31 | **Drift guard: `CursorPaginationProfile::keywords()` is asserted against the page's actually-emitted `page[…]` params** (`PaginatorTest::cursorPageEmitsExactlyTheQueryParamsItsProfileReserves`). The test introspects every `page[…]` key the cursor page emits across its full link set and requires it to equal the profile's reserved `page[…]` keywords. | `keywords()` is otherwise declarative-only; nothing enforced that the paginator's real query-param keys matched the profile's advertised ones. The test catches drift in either direction (renamed key, added param, stale keyword list). | this phase |
| 2026-05-31 | **Pagination rewritten as `Paginator` + `Page` under `haddowg\JsonApi\Pagination\*`.** Strategies: `PagePaginator`/`OffsetPaginator`/`FixedPagePaginator` implement `Paginator` (`paginate(request,items,totalItems): Page`); `CursorPaginator` is **standalone** (not a `Paginator`) — a cursor page has no total and its `prev`/`next` cursors are caller-supplied, so its `paginate()` takes the boundary cursors + has-more flags directly. `Page` VOs are `final readonly`, generic (`@template T`), iterable (`AbstractPage` re-keys to int), and own `linkSet()`/`pageMeta()`. `CursorBasedPage` omits `last` and carries the cursor profile. Rendered via `DataResponse::fromPage()`. **Deleted** yin's `Request\Pagination\*`, `Schema\Pagination\*` traits + `PaginationLinkProviderInterface`, and their tests/doubles. | The plan's structural rewrite (per the master-plan `Page` decision). `CursorPaginator` deviates from the uniform `Paginator` interface by necessity (no total), recorded here so a future reviewer doesn't "fix" it into the interface. `QueryParam::int` preserves yin's silent-default rule. | this phase |
| 2026-05-31 | **Single-threaded, no fan-out this phase.** Despite 4 `Page` subtypes, the cursor strategy diverges enough (before/after params, `last` omission, profile association, distinct `paginate()` shape) that the work was not mechanical; built sequentially in the main worktree. | Operational rule: batching is eligible only once the work is mechanical application of an established pattern. It wasn't. | this phase |
| 2026-05-31 | **Profile-defined query params surfaced via the owning paginator** (`CursorPaginator`/`CursorBasedPage` accessors for `page[after]/before/size`); no generic profile-query-param API. | Only the cursor profile defines query params this phase, and they are the cursor paginator's own inputs. Generic mechanism deferred until a non-pagination profile needs it. | this phase, post-1.0 |

## Open questions

_All kick-off open questions resolved 2026-05-31 with the maintainer — see the decision log rows above. New questions surfacing mid-phase are appended here and resolved interactively before phase close._

- ~~Profiles mutate documents vs strict-declarative.~~ **Resolved: finalisation hook allowed.**
- ~~Registry simple map vs quality-factor negotiation.~~ **Resolved: simple map, per-instance, eager.**
- ~~`ProfileAwarePaginator` sub-interface vs method-on-base.~~ **Resolved: `Page::profile()` method-on-base.**
- ~~How are profile-defined query params surfaced.~~ **Resolved: via the owning paginator's accessors; generic mechanism deferred.**
- ~~Implement `ext` parsing this phase.~~ **Resolved: yes (parse + 415/406 negotiation; no dispatch).**
- ~~Unsupported-profile → 406.~~ **Resolved (spec correction): ignore unrecognized profiles; 406/415 are for `ext` only.**

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. The profile abstraction exists (with the document-finalisation hook) and has at least one consumer (the cursor paginator) that registers and applies a profile end-to-end.
3. The negotiation layer parses both `profile` and `ext` media-type parameters (even if `ext` is not yet wired anywhere downstream).
4. **Unrecognized profiles are ignored, never rejected** (spec: "a server MUST ignore any profiles that it does not recognize"). An unsupported **`ext`** on `Content-Type` produces `415` and an unusable/unsupported-`ext` `Accept` produces `406`, both via typed exceptions.
5. Applied-profile responses carry the profile URI(s) in their `Content-Type` `profile` parameter and in `links.profile`, and set `Vary: Accept`.
6. PHPStan level 9 passes; CI matrix green; spec-tagged tests pass.
7. `docs/spec-compliance.md` updated for extensions/profiles and content negotiation rows.
8. `CLAUDE.md` updated with pattern entries for the new component kinds introduced this phase (profile interface implementations, profile-aware paginators, `Page` value objects) and any refinements to existing patterns.
9. **Pagination rewrite is complete and observable end-to-end.** `DataResponse::make($page)` (where `$page` is a `Page` subclass produced by a `Paginator`) renders a JSON:API document with correct `links.{first,prev,next,last}` and `meta.page.{...}` for the page-based and offset-based strategies; the cursor-based strategy produces `first`/`prev`/`next` and **does not** emit `last`. Yin's `PaginationLinkProviderInterface` is absent from the codebase.

### Verification plan

```bash
composer install
composer test
composer phpstan
composer cs-check

# Profile-specific spec coverage
vendor/bin/phpunit --group spec:extensions-and-profiles
vendor/bin/phpunit --group spec:content-negotiation
vendor/bin/phpunit --group spec:pagination

# Lowest-deps run
composer update --prefer-lowest --prefer-stable
composer test
```

Manual review:

- Write a throwaway custom profile in a sandbox, register it, hit a request requesting it, confirm: (a) the response advertises it on `Content-Type`, (b) `links.profile` carries it, (c) the `finalizeDocument()` hook ran, (d) requesting a profile **not** registered is **silently ignored** (no error, response simply does not advertise it).
- Confirm the `ext` parameter is parsed and exposed on the negotiated request representation; confirm an `ext` with no server support yields `415` (Content-Type) / `406` (Accept) — this is the hook a future Atomic Operations implementation will plug into.

## Handover output

Before declaring the phase complete, produce the following for Phase 3:

1. **Status table update** in `docs/PLAN.md` — Phase 2 → `Complete`, Phase 3 → `Ready`.
2. **Phase 3 plan review** — `docs/phase-3-middleware.md` already exists as a pre-drafted plan. Read it end-to-end against this phase's decision log and current repository state. Confirm it still covers at minimum:
   - PSR-15 middleware suite to ship: content negotiation middleware, request body parsing middleware, error handling middleware (catches `JsonApiException` instances → error documents), and a reserved-but-unimplemented slot for atomic-ops middleware (atomic operations is a post-1.0 candidate)
   - Middleware order and composition guidance
   - How middleware integrates with the orchestrator (`JsonApi` class) and the profile registry from this phase
   - PSR-7 request/response factory wiring for response creation inside middleware
   - Test plan for each middleware
   - Append revisions to the plan as a single commit; the actual kick-off revision happens at the start of Phase 3, but corrections forced by Phase 2 decisions belong here.
3. **Profile infrastructure documentation stub** — a placeholder under `docs/` to be filled out in the docs phase (just a heading + a paragraph linking to spec for now is fine).
4. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 3.
5. **Decision log finalised** — phase-local decisions captured here; any cross-phase decisions promoted to `PLAN.md`.
