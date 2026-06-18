# Counting is lazy by default; `pagination()` is the single source of truth

A paginator never runs a `COUNT` unless told to, and a total — wherever it appears
— is computed once and rendered consistently. This flips the previous count-by-default
behaviour (taken pre-1.0 when the cost is lowest) and reworks the pagination
resolution so a collection can genuinely be unpaginated.

**Lazy counting.** `PaginatorInterface` gains `wantsCount(): bool` — `false` by
default on the three count-based strategies (`PagePaginator`, `OffsetPaginator`,
`FixedPagePaginator`), which each gain a fluent `withCount()` that returns a clone
with the flag set; the `CursorPaginator` is inherently count-free and returns a
constant `false` (it takes neither `withCount()` nor `?withCount`). So a plain paged
request now renders count-free — no `meta.page.total`, no `last` link, `next` driven
by a window+1 `hasMore` probe, zero `COUNT` queries. Counted output requires either
the author's `withCount()` on the paginator (always counts) or the client's
`?withCount=_self_` against a `countable()` target (counts on demand, under the
profile).

**`countable()` everywhere + the `_self_` token.** `countable()` is added to
`AbstractResource` (it was relation-only), so a primary collection can be made
client-countable. The reserved `?withCount` token `_self_` names the primary
collection (its total lands top-level in `meta.total`, mirrored by `meta.page.total`
when paginated), composing with relation names (`?withCount=_self_,comments`). The
document gate validates `_self_` against the new resource-level
`CountableSelfInterface::isCountable()` — a bare serializer (no resource) lacks the
capability, so `_self_` against it is rejected `400`; relation names keep their
existing `CountableControlsInterface` gate. A relation literally named `_self_` is
rejected at build time (the token would be ambiguous).

On a **related-collection render** (`GET /{type}/{id}/{rel}`) the document's primary
serializer is the *related-type resource*, not the relation — so `_self_` must be gated
by the **relation's** `countable()`, not the related resource's. The transformation
therefore carries an optional `countableSelfOverride`, which
`RelatedResponse::fromCollection()/fromPage($selfCountable)` sets from the owning
relation's `countable()`; the gate prefers the override when present and falls back to
the primary serializer's `isCountable()` otherwise. So a countable relation's collection
is `_self_`-countable even when the related resource is not.

**`pagination()` is the single source of truth.** Previously
`AbstractResource::pagination(): ?PaginatorInterface` returned `null` and the
resolver coalesced `null → server default`, so a resource could never be
unpaginated. Now `pagination(?PaginatorInterface $serverDefault)` receives the
resolved server default and its return is used verbatim: the base returns the
argument (inherit), an override pins a strategy, and a `null` return means *no
pagination* (fetch-all → `meta.total` rendered unconditionally, since the whole
collection is in hand). Relations mirror this:
`AbstractRelation::pagination(?PaginatorInterface $fallback)` returns its own
paginator `?? $fallback`, and `withoutPagination()` short-circuits to `null` before
the fallback so an explicit opt-out cannot be overridden. The argument is the
paginator, not the whole `Server`, keeping resources decoupled from `ServerInterface`.
These signature changes (on `AbstractResource`, `AbstractRelation`,
`RelationInterface`) and the `PaginatorInterface::wantsCount()` addition are breaking
for any subclass/implementor — taken pre-1.0.

**Profile rename.** The *Relationship Counts* profile is renamed *Countable*
(`Schema\Profile\CountableProfile`, new URI slug
`https://haddowg.github.io/json-api/profiles/countable/`) because `?withCount` is no
longer relationship-only — it now also counts the primary collection via `_self_`.
The `?withCount` parameter name and the `withCount` negotiation keyword are
unchanged; only the profile identity/URI moved. Supersedes the
`relationship-counts` slug from ADR 0065.
