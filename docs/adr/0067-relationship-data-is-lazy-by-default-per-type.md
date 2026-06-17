# Relationship linkage `data` is lazy by default, per relation type

ADR 0025 made load-aware linkage opt-in (`dataOnlyWhenLoaded()`, off by default), so
the safe-against-N+1 behaviour required every relation to remember to ask for it — and
a single forgotten call across a collection forces one lazy load per parent for every
to-many relation just to render identifiers. We flip the default: a relation is **lazy
by default** — links-only until loaded/included, never forcing a fetch — with the
default keyed **per relation type** on whether resolving the linkage is free. `BelongsTo`
and `MorphTo` (the foreign key / morph id sits on the owning model, so the identifier is
already in hand) default **eager**; the to-many relations and `HasOne` (the key is on the
*related* model, so resolving it is a query — the same N+1 risk as a to-many) default
**lazy**. The opt-in setter `dataOnlyWhenLoaded()` is **removed** (pre-1.0, no
back-compat) and replaced by its inverse `withData()`, which forces a lazy relation
eager when rendering identifiers is acceptable (or the value is reliably preloaded);
the queryable `emitsDataOnlyWhenLoaded()` getter and the load-state seam (ADR 0025) are
unchanged. To keep the lazy default safe, the empty-object guard moves into
`AbstractRelationship::transform()` and now keys off **rendered**-link presence: a
relationship that would emit neither links (suppressed via `withoutLinks()`, or both
endpoints unexposed) nor meta always emits its `data`, since a relationship object can
never be empty `{}` (JSON:API requires at least one of links / meta / data).

Status: supersedes the opt-in surface of [ADR 0025](0025-per-relation-linkage-only-when-loaded-policy.md) (the load-state seam it introduced is retained).
