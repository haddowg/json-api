# v1 coverage-gap review: write-path security + content negotiation

> The two areas [`v1-readiness-review.md`](v1-readiness-review.md) flagged as **not
> examined**. Adversarially-verified static review across core + the Symfony bundle
> (integration witness). 24 findings survived verification, 5 refuted.

## Verdict: **not safe to freeze as-is — 4 narrow, cheap blockers**

The reassuring part is most of it. The blockers are about the library not honouring
its **own** `readOnly()` contract in two spots, and one **spec MUST** violation in the
negotiation contract — both irreversible after the semver freeze, neither a remote
exploit. Fix the four and the two areas are safe to tag.

## Confirmed safe (verified, not assumed)

- **Over-posting / mass-assignment is structurally impossible.** Hydration is
  **allow-list** based on *every* path — `AbstractResource::hydrateAttributes()`/
  `hydrateRelationships()`, `Map::hydrate()`, `HydratorTrait` all iterate the
  **declared** field inventory and look each up in the body; they never iterate client
  keys, so an undeclared `isAdmin`/extra attribute or relationship is never read.
- **Body parsing is bounded** — all four production `json_decode` sites use depth
  `512` + `JSON_THROW_ON_ERROR`; a `>512`-deep body 400s before any recursion. No ReDoS
  (linear scanner, disjoint regex classes, no nested quantifiers).
- **Errors don't leak** — production rendering is `kernel.debug`-gated, defaults safe,
  strips stack-frame args.
- **The no-authz boundary is deliberate and correctly documented** — core exposes no
  per-operation/per-field authz hook by design; `security.md` scopes authz, body-size,
  and prod error verbosity to the consumer.
- **Content-Type `415` is spec-correct**; top-level read-only attributes and
  client-generated ids are honoured at hydration.

## Freeze blockers

**1 · Map (nested-object) children ignore `readOnly()` at hydration — `[high, core]`**
`Map::hydrate()` (`src/Resource/Field/Map.php:71-75`) iterates its children and calls
`$child->hydrate()` whenever the key is present, with **no `isReadOnly` guard** — and
`FieldInterface::hydrate` (`FieldInterface.php:78`) carries **no context arg**, so a
child can't self-gate even in principle. Contrast `AbstractResource::hydrateAttributes:476`,
which *does* gate top-level fields. The bundle validator compounds it: `ResourceValidator`
nested-collection skips read-only children *and* allows extra fields, so a read-only Map
child is validated by nothing and gated by nothing. **Attack:** `Map::make('settings')->fields(Str::make('role')->readOnly())`;
`PATCH {"data":{"attributes":{"settings":{"role":"admin"}}}}` writes `admin`.
*Not* over-posting (the declared-child loop blocks undeclared keys) — a `readOnly` bypass
on a declared nested child.
**Fix:** extend `FieldInterface::hydrate` with a `$creating`/context parameter and add the
`isReadOnly($creating)` continue to `Map::hydrate`. **This is an API-signature change that
can't be added cleanly post-freeze — that's why it blocks.** Needs an ADR + a conformance test.

**2 · Bundle whole-resource write applies relationships without core's `readOnly()` gate — `[medium, bundle]`**
`CrudOperationHandler::create()/update()` call `withoutRelationships()` so core's gate
(`AbstractResource::hydrateRelationships:497` `if ($relation->isReadOnly($creating)) continue;`)
never sees them, then re-applies via `extractRelationships()` (no `isReadOnly` check) +
`applyRelationships()` → `mutateRelationship(Replace)`. Its own docblock (`:605-606`)
**falsely** claims read-only relationships are skipped. **Attack:** a resource declares
`HasOne::make('owner')->readOnly()` (server-assigned); a client PATCHes
`data.relationships.owner.data.id = <attacker>` and overwrites it on both providers.
**Fix:** thread `$creating` into `extractRelationships()` + add the `isReadOnly($creating)`
continue; correct the docblock; dual-provider conformance test. Bundle-only.

**3 · Accept/`406` over-strict — spec MUST violation — `[high, core]`**
`MediaType::isValid()` (`src/Request/MediaType.php:28-48`) returns false on the **first**
`application/vnd.api+json` instance carrying a non-ext/profile parameter, never checking
for a clean sibling. Both `validateAcceptHeader()` and `validateContentTypeHeader()` route
through it. Per JSON:API 1.1: Content-Type MUST `415` on any param (isValid is *correct*
here), but for Accept the server MUST **ignore non-conforming instances and `406` only if
ALL are modified**. Repro: `Accept: application/vnd.api+json; charset=utf-8, application/vnd.api+json`
→ wrongly `406` (a clean instance is present; spec mandates `200`). Includes the bare
`q`-weight sub-case (`q` is mishandled as a media-type parameter).
**Fix:** split the Accept rule from the Content-Type rule — keep strict any-param→415 for
the single Content-Type; for Accept, ignore JSON:API instances carrying a forbidden
parameter (and the trailing `q`/accept-ext tokens) and `406` only when none conform. ADR +
tests.

**4 · The negotiation docs document the bug as correct — `[medium, docs]`**
`content-negotiation.md:21-24` and `spec-compliance.md:126` present the over-strict Accept
behaviour as correct/tested, hiding the gap. **Fix in lockstep with #3.**

## Defer to post-1.0 (additive / safe-direction)

- `MediaType` uses a `stripos` substring match for `application/vnd.api+json` — can only
  **over**-reject a hypothetical foreign `+json` subtype, never bypass (acceptance never
  keys off `isValid()==true`). One-line parse-to-boundary later.
- `RequestBodyInvalidJson` echoes the raw body into `meta.original` un-gated — reflects the
  requester's **own** input back to them (not a leak/XSS); only a response-size amplification,
  already bounded by the documented body-size cap. Optional truncation.
- The param-name regex omits some RFC 9110 `tchar`s (`_ ~ ! # $ % & | ^`) → a `415` missed on
  an uncommon param name (under-strict, Content-Type). Tighten the set.
- The param regex scans inside quoted values → an uncommon quoted `profile`/`ext` value
  containing `; name=` is misread as a param (over-strict). Strip quoted values first.
- Bundle: `hidden()` relations aren't filtered on the **standalone** relations path
  (cosmetic — the dev declared/registered them).

## Doc fixes (security.md)

- Add an **"allow-list hydration"** positive guarantee (writes only declared, non-`readOnly`
  fields for the context; undeclared attributes silently ignored; client-gen ids rejected
  unless opted in) — currently absent.
- Clarify the "library-controlled, no untrusted echo" sentence (`:58-59`) — accurate for
  status/code/title/detail, but the media-type errors echo the sent header and
  `RequestBodyInvalidJson` echoes the raw body (the requester's own input).
- If either read-only gap is **deferred** rather than fixed: `security.md` MUST warn that
  `readOnly()` is not enforced on Map children / whole-resource relationships, and the
  consumer must use `cannotReplace()`/`cannotRemove()` or a handler check.
- Bundle ships **no** `security.md` — the artifact most consumers install surfaces none of
  the deployment duties (cap body size at the proxy, keep `kernel.debug` false in prod,
  place auth outside the JSON:API route group, hydration ≠ per-user field authz). Add or
  link core's.

## Coverage gaps (what even this review didn't fully do)

- Static + spec review — the two write exploits were **traced by reading**, not executed
  against a live Doctrine kernel (`WriteConformanceTestCase` not run for them).
- Hand-written **standalone hydrators** weren't audited for re-exposing a read-only column
  (read-only enforcement there lives in the author's closure).
- `q`-value **preference ordering** (RFC 7231 quality ranking) out of scope — the library
  validates, it doesn't pick a representation.
- No fuzzing — ReDoS-safety rests on reading the regexes, not timing.
