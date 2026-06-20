# Extensible in-memory filter and sort handlers

The reference `ArrayFilterHandler` and `ArraySortHandler` now accept a constructor
list of author **arms** — `ArrayFilterArmInterface` (a `supports()` test plus a row
`predicate()`) and `ArraySortArmInterface` (a `supports()` test plus a per-row sort
`value()`) — consulted when no built-in arm recognises a `FilterInterface` /
`SortInterface`, before `UnsupportedFilter` / `UnsupportedSort` is raised. So a
consumer's own filter/sort value object can execute on the in-memory provider
instead of being a hard server-config `500`, which previously forced a wholesale
custom handler.

Both handlers stay `final`: the seam is **composition, not inheritance** (an arm is a
small focused object), and the built-ins always win — arms are a fallthrough, never an
override of `Where`/`SortByField`. The arm list defaults to `[]`, so every existing
bare `new ArrayFilterHandler()` / `new ArraySortHandler()` is unchanged.

A sort arm contributes a per-row **key** rather than a standalone ordering, because
`ArraySortHandler` is one lexicographic `usort` cascade over the directives in
significance order — a key weaves a custom directive into that cascade (primary,
secondary, or tie-breaker) exactly as a field sort does, where a standalone re-sort
could not.

This is the in-memory half of the framework's extensible-handler seam: the
storage-agnostic core supplies the conformance witness so a **portable** custom
filter/sort (e.g. order-by-related-count) ships an in-memory arm here and a push-down
arm in the data-layer adapter, and the two stay behaviourally identical under the
shared conformance suite. An inherently storage-specific filter (a raw-query scope)
simply ships no in-memory arm — its key is undeclared on an in-memory resource (a
clean `400`), or, if declared, the unchanged `UnsupportedFilter` `500` reports the
misconfiguration. The contracts are deliberately provider-agnostic; the adapter
declares its own query-typed arm contract (e.g. the bundle's `DoctrineFilterArm` over
a `QueryBuilder`).
