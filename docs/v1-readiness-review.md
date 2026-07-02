# v1 public-API readiness review (strategic pass)

> Companion to [`release-readiness.md`](release-readiness.md). That doc is the
> mechanical 1.0 checklist (spec re-walk, naming/type narrowing, security, release
> mechanics). **This** doc is the broader strategic review requested for Phase 5:
> consistency, composability, extendability, DX, real-API gaps, documentation
> coverage/consistency, bundle-as-integration-witness friction, and semver
> freeze-risk — with the `haddowg/json-api-symfony` bundle as the integration lens.

## How this was produced

A multi-agent review over both repos (core under review; the Symfony bundle as the
integration witness). **15 subsystem deep-reads** → **8 cross-cutting dimension
reviewers** (consistency, composability, extendability, DX, real-API gaps, docs,
bundle-witness, encapsulation/semver) → **per-finding adversarial verification**
(every claim re-read against the cited code) → synthesis. 105 agents; **81 raw
findings → 79 survived verification, 2 refuted.** Severity of the survivors:
**7 high · 21 medium · 51 low.** Each finding carries `file:line` evidence; the
verification pass is why the refuted/overstated ones were dropped rather than shipped.

## Verdict: **NOT YET — freeze after a small, well-scoped pre-1.0 pass**

The architecture is **freeze-worthy** and the spec surface is broad and
test-witnessed. The 79 findings collapse to ~a dozen distinct issues; only a handful
are genuine freeze hazards. The reason to do a short pass before tagging is
**irretractability**: most of these are one-line or doc-only fixes that are *free
now* and *breaking-or-permanent after the tag*. Nothing found is a structural hole.

Two items are real **API-shape decisions** (need a call + an ADR); the rest are a
correctness bug, surface hygiene, and a documentation-freeze sweep.

---

## Must-fix before the freeze

Ranked as the synthesis produced them. The **(CORE)** / **(docs)** tag marks whether
it touches frozen code or only documentation; **BC** marks why it can't wait.

### A. API-shape decisions (need a maintainer call + ADR)

**1 · `singular()` filter flag is an inert, documented no-op** — *high, CORE, BC: removal breaking*
`Where`/`WhereIn`/`WhereNotIn` expose a fluent `singular()` that sets `$singular`,
but no shipped handler reads it: `ArrayFilterHandler::toList()` (and the bundle's
`DoctrineFilterHandler::toList()`) split on the delimiter **unconditionally** — so
the `filter[name]=Smith, Jr.` footgun the docs say `singular()` prevents *still
fires*. `src/Resource/Filter/Where.php:20,38`; `$singular` is only ever set/copied,
never read.
**Decision:** implement it (skip the split, return `[$value]`, in both reference
handlers + a behavioural test) **or** remove the method + property + docs from all
three VOs. Either way it must not freeze as a no-op.

**2 · Handler extension seam can't reach serializer/hydrator resolution through an interface** — *high, CORE, BC: re-typing breaking*
`OperationHandlerInterface` is the headline extension point, but the
`OperationContext` it receives types `$server` as the render-minimal
`ServerInterface` (`src/Operation/OperationContext.php:24`), which has no
`serializerFor()`/`hydratorFor()`. Serializer resolution lives on a public
`SerializerResolverInterface` that `ServerInterface` doesn't extend; **hydrator
resolution has no public interface at all** (`hydratorFor` exists only on the
*final* concrete `Server`/`ResourceRegistry`). So any non-trivial custom handler must
`\assert($server instanceof Server)` and couple to a final class — and `docs/server.md`
sanctions that as canonical.
**Decision:** add a `HydratorResolverInterface` mirroring `SerializerResolverInterface`,
have `Server`/`ResourceRegistry` implement it, and make resolution reachable through
what `OperationContext` hands a handler (compose the interfaces, or add resolver
accessors). The seam shape must be settled at freeze.

### B. Correctness bug (one-line + regression test)

**5 · MorphTo emits self/related links ignoring endpoint-exposure flags** — *medium, CORE, BC: behaviour*
`MorphTo::buildRelationship` calls `withConventionLinks()` with a single arg
(`src/Resource/Field/MorphTo.php:72`), taking `exposeSelf=true/exposeRelated=true`.
A `MorphTo` that suppresses an endpoint via `withoutRelatedEndpoint()` /
`withoutRelationshipEndpoint()` still emits links pointing at a host the handler
404s — violating the ADR 0033 link-consistency invariant. Every sibling relation
threads the two flags; MorphTo is the lone outlier. One-line fix + a render test.

### C. Surface hygiene (irretractable post-tag)

**4 · Dead `use` imports + 23 broken `{@see}` links** — *medium, CORE, BC: permanent doc rot*
The #15 Interface-suffix rename left ~7 dead imports of nonexistent classes
(`AbstractResource.php:21,24`, `AbstractField.php:11`, `FieldInterface.php:8`,
`SchemaCompiler.php:12,42`, `JsonApiOperationBuilder.php:10`) and 23+ broken `{@see}`
links on the most-read public files — surviving cs-fixer/PHPStan only because a
docblock token fools `no_unused_imports`. Mechanical sweep + a CI link-check gate.

**8 · `SchemaCompiler` silently drops `UniqueItems`** — *medium, CORE, BC: completeness*
`applyConstraint()` handles Min/MaxItems but has no `UniqueItems` arm
(`src/Validation/SchemaCompiler.php:228-230`, `grep UniqueItems` = 0), so a declared
`UniqueItems` falls through `default` and is dropped from the optional structural
schema despite `validation.md:33` promising `uniqueItems`. One-line, lossless +
a `SchemaCompilerTest` for the array-bounds trio.

**9 · Inert `retainFieldName()` builder on `AbstractRelation`** — *low, CORE, BC: removal breaking*
`src/Resource/Field/AbstractRelation.php:42,112-117` — property + setter, **no read
site, no getter, no consumer, no ADR**. Core performs no field-name transformation,
so there is nothing to retain. Remove it + the `fields.md:327` row (or implement).

**10 · Internal/public boundary enforced two incompatible ways** — *low, CORE, BC: permanent*
7-8 files under `Internal\` (the freeze-exempt boundary) vs ~26 whole-class
`@internal`-tagged types in *importable* public namespaces (all of `Transformer/`,
`Schema/Document/*`, `Schema/Data/*`, plus `QueryParam`/`MediaType`/`Entry`/`Accessor`).
`release-readiness.md:54` already declares a co-equal policy, and all cited types
satisfy it (no `@internal` leaks through a public *signature*). The genuine residual:
the canonical copy-this adapter example (the reference `ArrayFilterHandler`/
`ArraySortHandler`, consumed directly by the bundle) reaches `@internal`
`Field\Accessor` (`src/Resource/Field/Accessor.php:16`). **Decision:** relocate the
~26 types under `*\Internal\` *or* ratify the `@internal`-PHPDoc policy as co-equal
binding — and resolve the Accessor-in-example leak.

**11 · Asymmetric / dead public members freeze irretractably** — *low, CORE, BC: private→public breaking*
(a) `RelatedResponse::$parent`/`$relationshipName` are public though the class
docblock says they don't affect rendering and nothing reads them — sibling
`IdentifierResponse` keeps the equivalents private (`RelatedResponse.php:37-38`).
(b) `ResourceRegistry::setResolver()`/`setRelationshipLoadState()` are public `void`
mutators that break the immutable-Server contract — should be `@internal`.
(c) `AppliesPaginationTrait` is public but all-private (zero extension value) —
`@internal`.

### D. Documentation freeze sweep (docs become the v1 reference)

**3 · `SortHandlerInterface::apply()` documented with a superseded signature** — *high, docs*
`docs/sorts.md:89` and `docs/adapters.md:57` teach the pre-ADR-0016 per-directive
shape `apply(SortInterface, query, descending)`; the frozen interface is the batched
`apply(list<SortDirective> $sorts, mixed $query): mixed`
(`src/Resource/Sort/SortHandlerInterface.php:35`). A custom handler written from the
docs **does not satisfy the interface**, and the worked foreach teaches the exact
multi-key bug ADR 0016 exists to prevent. Rewrite both.

**6 · `serializers.md` falsely denies resolver injection to override/standalone serializers** — *medium, docs*
`serializers.md:158-166` says an override/standalone serializer gets no resolver
injection; `ResourceRegistry::makeSerializer()` always calls `injectResolver()`
(`ResourceRegistry.php:306`), which injects into *any* serializer implementing
`SerializerResolverAwareInterface` (ADR 0032 capability). The doc steers users away
from a supported feature.

**7 · `responses.md` predates ADR 0019; `validation.md` vocab table is stale** — *medium, docs*
`responses.md:7` says "five" responses and `:50-51` "set the status on the PSR-7
response after rendering" — vs six responses, `NoContentResponse` (204), and
`withStatus(int)` (`AbstractResponse.php:113`). The `handle()`/`dispatch()` return
union docs omit `NoContentResponse`. `validation.md`'s vocabulary table omits
`Sequentially`/`AtLeastOneOf`/`CompareField`. Three serializer capability interfaces
and `MorphToMany` have zero non-ADR doc coverage.

---

## Defer to post-1.0 (additive — safe in any 1.x)

All recorded with seams in place; none gate the freeze. Most need only a one-line doc
note naming the boundary so API authors aren't surprised.

- **No core 422 / constructable `Error`-carrying exception** — every ad-hoc typed
  error needs a full subclass (`AbstractJsonApiException` ctor is `(message, status)`,
  `getErrors()` abstract; ~38 subclasses hand-write it). The bundle had to define its
  own `ValidationFailed`; ADR 0018 already flexed `ErrorResponse` for external 422s.
  Mitigated by `ErrorResponse::fromErrors()` at the response layer. Add a
  `UnprocessableEntity` and/or a generic `JsonApiError(int $status, Error ...$errors)`.
- **Extension dispatch / Atomic Operations / bulk writes** — ADR 0011, negotiation
  seams placed.
- **405 Method Not Allowed shape** from `OperationFactory` (programmatic/PSR-15 path
  500s; framework routers return 405 upstream).
- **Polymorphic (MorphTo/MorphToMany) + BelongsToMany pivot WRITES** — read/declare-only
  at v1; custom-hydrator/`fillUsing` escape hatch exists. Document the default-apply
  morph-type-drop.
- **First-class cursor pagination through the generic engine** — deliberately
  second-class (ADR 0015; `WindowInterface` reserves the seam). One-sentence doc note.
- **`BodyCarryingOperationInterface` marker** + `ResourceRegistry::hasResourceFor()`/
  `hasHydratorFor()` predicates — optional ergonomics, no current consumer need.
- **`getResource()` return-type tightening** + resource-identifier error
  `source.pointer` fallback — SHOULD-level, additive.
- **Closed-compiler / open-attach asymmetry** — `SchemaCompiler` is `final` with a
  closed `instanceof` switch, so a custom `ConstraintInterface` (the open `constrain()`
  hatch) is honoured by adapters but never by the structural path. ADR-0021-recorded;
  only a one-line `constrain()` doc cross-reference needed now.
- **Two parallel hydration paths** — `AbstractResource::hydrate()` omits the
  `validateDomainObject()` hook the `AbstractHydrator` path advertises (no behaviour
  split today; uneven override surface). Add the no-op hook for parity; document the
  relationship-mutation gating split (`UpdateRelationshipHydratorInterface` is
  core-tested but the bundle routes through its own `DataPersister::mutateRelationship()`
  per bundle ADR 0017).

---

## Confirmed strengths — lock these in as-is

Independently validated across multiple dimensions:

- **Capability-composed type model** — `RendersRelationsTrait` +
  `SerializerResolverAwareInterface` let a hand-written serializer render relations
  with no `AbstractResource`, proven by the bundle's standalone `PostSerializer`.
- **`uriType` decoupling** composes with the link layer via an optional interface check.
- **`constrain()`** — clean typed escape hatch, class-based adapter dispatch, no
  parallel `$id` registry.
- **interface-for-contract / abstract-for-shared-impl / final-for-leaf-VO** discipline
  applied uniformly; byte-identical handler/dispatch return unions.
- **Lifecycle-extraction seams** (`RequestValidator`, `Server::dispatch()`,
  `InternalServerError::for()`) let the bundle drive the whole lifecycle without
  instantiating any `Middleware\*` class — the core validation that motivated the bundle.
- **`spec-compliance.md`** maps every tracked JSON:API 1.1 requirement to a grouped test.

---

## Coverage gaps (what this review did *not* fully verify)

Honest limitations — worth a targeted second look before the tag:

1. **Content negotiation** — `RequestValidator::negotiate()` and the 406/415 paths
   weren't independently walked; q-values, multiple `ext` params, and profile params
   are under-verified.
2. **Spec-compliance depth** — the "every MUST/SHOULD is test-covered" claim was
   accepted on spot-checks, not a re-walk of all ~141 rows confirming each
   `#[Group('spec:…')]` test actually *asserts* the requirement (vs merely being
   tagged). The `singular()`/`UniqueItems` findings show the filtering/sparse rows are
   fragile.
3. **Security surface** — `security.md` guidance, firewall 401/403 mapping, field-level
   read/write authorization, and mass-assignment/over-posting protection on hydration
   were not examined; none of the 79 findings cover them.
4. **Profiles** — seen only as an extendability strength; profile link emission,
   negotiation, and schema-contribution weren't verified end-to-end.
5. **Lower-ranked findings** — the ~8 headline claims were re-verified directly; the
   ~60 low-rank ones rest on the verifier notes, not a fresh re-open of every line.
6. **Gates not run here** — "both gates pass clean" / "X is untested" rest on the
   findings' greps, not an independent `composer test`/`phpstan`/`cs-check` run.
7. **PHP 8.3/8.4/8.5 matrix** — version-specific surface (readonly/enum/property-hook)
   and forward-compat of frozen signatures across the range were out of scope.
8. **Performance** — public-surface efficiency (wide `JsonApiRequestInterface`
   laziness, transformer dedup cost, push-down query shapes) only noted anecdotally.

---

## Refuted (verification dropped these — recorded so the omission reads as a decision)

- *"A field/relation/`Map` child with a null column and no `fillUsing` silently
  no-ops on hydrate"* — refuted: the premise is structurally impossible given how
  `AbstractField` resolves the write target.
- *"`@internal Accessor` is the dependency of the public copy-this reference handlers"*
  — folded into finding #10 as the one genuine residual rather than a standalone leak.

---

## Suggested work breakdown

Three buckets, each a natural PR + (where it shapes the surface) an ADR:

1. **Two ADR'd API decisions** — `singular()` (implement vs remove); the
   `HydratorResolverInterface` + `OperationContext` resolution seam. *These are the
   only items needing a maintainer call before anyone writes code.*
2. **One code-fix sweep PR** — MorphTo links + test; dead imports/`{@see}` + CI
   link-check; `UniqueItems` arm + test; `retainFieldName()` removal; the
   asymmetric/dead members; the internal-boundary decision.
3. **One doc-freeze PR** — sorts/adapters signature, serializers resolver claim,
   responses + validation tables, the missing capability/MorphToMany doc coverage,
   plus the one-line post-1.0 boundary notes.
