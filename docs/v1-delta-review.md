# v1 freeze — delta review (post-2026-06-13 surface)

> Companion to `v1-readiness-review.md` and `v1-security-negotiation-review.md` (both
> 2026-06-13). Those reviewed the surface up to ~ADR 0069; this reviews everything
> that shipped after (core ADRs 0070–0084, bundle ADRs 0077–0086) and re-confirms the
> prior must-fixes still hold. Run 2026-06-21 as a 36-agent workflow (7 cluster
> inventories + re-confirm → 7 freeze-lens dimension reviewers → per-finding
> adversarial verification → synthesis). 20 findings, all accuracy-confirmed, 2
> freeze-blocking.

**VERDICT: GO-WITH-FIXES** — the delta surface is sound and all 11 prior must-fixes hold, but two permanently-freezing author-closure/interface naming clashes must be settled before the tag; everything else is additive-safe or docs.

## Must fix before the tag

| cluster | severity | issue | file:line | fix |
|---|---|---|---|---|
| request-aware-predicates | med | New request-first `$when` predicates (`hidden`, `cannotReplace/Remove/Add/BeIncluded`) invert the model-first author-closure convention of the sibling/same-delta closures (`extractUsing`, `computedUsing`, `identifierMeta`, validation `when()`) — adjacent fields silently swap arg order, and the feature itself ships both shapes (`readOnly`/`writeOnly` 1-arg vs `hidden`/`cannot*` 2-arg) | `src/Resource/Field/AbstractField.php:395`; `AbstractRelation.php:240,263,317,346`; cf. model-first at `AbstractField.php:116,121,432`, `AbstractRelation.php:402` | Settle one canonical author-closure arg order and apply uniformly (precedent + `identifierMeta` are model-first), or document the deliberate split prominently. Closure param order freezes permanently at 1.0. |
| on-flatten-linkage-seam | med (impact) / low (cosmetic) | `RelationshipLinkageInterface::linkageFor()` breaks the verb-phrase method-name convention of the seam family it explicitly claims to mirror (`paginateRelationship`/`countRelationship`/`isRelationshipLoaded`) | `src/Serializer/RelationshipLinkageInterface.php:50` (cf. `RelationshipPaginationInterface.php:52`, `RelationshipCountInterface.php:43`, `RelationshipLoadStateInterface.php:40`) | Rename to a verb phrase, e.g. `linkageForRelationship(...)`/`resolveRelationshipLinkage(...)`, before the tag. Single bundle implementor — cheap now, breaking after. |

These are the only two findings that are breaking-or-permanent after the tag. Both are public-symbol shape (closure arity/order; interface method name) with no wire/spec/security impact — but both freeze permanently at 1.0.

## Defer to post-1.0 (additive-safe)

- **OpenAPI generated-document fixes (not frozen symbols, fixable any 1.x):**
  - `sort`/`include` parameters emit a single-token enum that rejects every legal multi-value request (`?sort=title,-wordCount`, `?include=author,tags`) — `core/src/OpenApi/OperationProjector.php:695,717`. Real conformance defect; worth fixing pre-tag for the headline feature but not freeze-blocking. Use the array form (`style:form, explode:false`) or a comma pattern.
  - `PaginatorInterface` carries no kind self-description → bundle `instanceof`-discriminates and silently projects a custom paginator as count-based `Page` (`PaginatorKindResolver.php:35-44`). Additive-safe remedy: optional `DescribesPaginatorKind` interface with the class-map fallback. **Record the decision so the gap is intentional.**
- **`@internal` / annotation hygiene:** add class-level `@internal` to the three OpenAPI metadata resolvers (`PaginatorKindResolver.php:33`, `TagNameResolver.php:22`, `IncludePathResolver.php:33`) — doc-only, sets the contract boundary correctly at the freeze.
- **Naming polish (cosmetic, but class names freeze):** consider renaming cursor exceptions `MalformedCursor`/`StaleCursor` → `CursorMalformed`/`CursorStale` to match noun-first house convention (`QueryParamMalformed`). Wire contract (`CURSOR_MALFORMED`/`CURSOR_STALE`) is already correct. Optional: align core `OperationType` backing values to the bundle `Operation` case-name convention to drop the lcfirst name-bridge (`MetadataSource.php:284-300,485-488`).
- **Stale docs (docs-only, code is correct):** `boolean()` filter Pattern claim is wrong in both `core/docs/filters.md:279` and `bundle/docs/data-layer.md:438` (real pattern accepts `on/off/yes/no`/empty/case-insensitive). Add the request-aware `$when` overloads to `core/docs/fields.md` (currently bundle-side only).
- **Docblock/contract tidy:** delete the stale `$present` sentence from `RelationshipLinkage.php:29-38` (constructor has only `$data`); decide whether `RelationshipLinkageInterface` should consult the MorphToMany builder or tighten its "any to-many" docblock to monomorphic-only (`AbstractRelation.php:823` vs `MorphToMany.php:44-92` — inert today).
- **Bundle witness consolidation (no core/wire change):** `TypeMetadataResolver::relationNamedIncludingHidden` should delegate to the public core seam it asked for instead of re-iterating (`TypeMetadataResolver.php:87-92` vs `AbstractResource.php:1006-1015`); note `IncludePathResolver`'s duplicated include-safeguard walk for a post-1.0 core enumerator. Confirm/decide intended extension surface for the non-final intermediates (`Str`/`DateTime`/`BelongsTo`/`HasMany`) — the rule is mechanically consistent (non-final iff a shipped subclass exists) and removing `final` later is non-breaking.
- **Boolean empty-value semantics:** confirm intent that `filter[active]=` means `false` vs no-op (`HasValueConstraints.php:139`, `Where.php:82`); behaviour-only, no symbol freeze, but the chosen semantics lock in once authors rely on them.
- **Doctrine arm placeholder collision:** documented author responsibility with no programmatic guard (`DoctrineFilterArmInterface.php:29-30`, `DoctrineFilterHandler.php:652-655`); `apply()` signature is correct — add a worked example or an optional 1.x helper.
- **Intentional exceptions reviewed, no change:** `Schema` non-readonly is a documented self-referencing-graph exception with immutability enforced via private state + final + clone-on-write (`core/src/OpenApi/Schema.php:21`); single-case `ParameterStyle` enum is additive-safe (new cases land any 1.x).

## Re-confirm: prior must-fixes

**All 11 must-fixes from the 2026-06-13 review hold — no regressions.** Verified in current source: resolver seam (`ResolvingServerInterface.php`), singular filter (`Where.php:59-61`), MorphTo links honour exposure (`MorphTo.php:78-82`), SchemaCompiler `UniqueItems`, Map children readOnly gating (`Map.php:81-83`), bundle whole-resource write readOnly gate (`CrudOperationHandler.php`), MediaType Accept/Content-Type split (`MediaType.php:28-75`), `retainFieldName()` removal (`AbstractRelation.php`), `RelatedResponse` `$parent/$relationshipName` removal (`RelatedResponse.php:43-49`), `ResourceRegistry` `@internal` (`ResourceRegistry.php:32`), dead-import cleanup (`AbstractResource.php`).

## Per-cluster freeze readiness

- **openapi-core** — ready-with-fixes: no frozen-symbol blockers, but the `sort`/`include` single-token enum (`OperationProjector.php:695,717`) rejects valid multi-value traffic and the missing paginator-kind self-description mis-projects custom paginators — both additive, worth pre-tag.
- **openapi-bundle** — ready: only `@internal` hygiene on three resolvers and the post-1.0 include-path enumerator consolidation; nothing breaking.
- **filter-library** — ready: cursor exception naming + boolean-empty semantics + two stale `boolean()` doc rows are all cosmetic/docs/behaviour, none freeze-blocking.
- **handler-arm-seam** — ready: arm seam composes cleanly (first-match, built-ins win, alias threaded); placeholder-collision is a documented author responsibility, `apply()` signature correct.
- **request-aware-predicates** — blocked: the request-first `$when` arg order/arity clash with the model-first convention (and intra-feature `readOnly` 1-arg vs `hidden`/`cannot*` 2-arg) must be settled before the tag.
- **on-flatten-linkage-seam** — blocked: `linkageFor()` interface method name must be renamed to a verb phrase before the tag; the stale `$present` docblock and MorphToMany seam-skip are additive/docs.
- **identifier-meta** — ready: shipped + merged 2026-06-21 (core PR #74 / bundle #51), model-first `identifierMeta(parent,related,request)` resolver is itself the convention the `$when` predicates should align to; no open items.
