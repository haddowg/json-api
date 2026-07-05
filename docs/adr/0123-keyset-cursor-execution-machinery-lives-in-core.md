# Keyset (cursor) execution machinery lives in core under `Collection\Keyset`

The Symfony and Laravel integrations each carried a byte-identical copy of the
keyset machinery their data layers compose to execute a `CursorWindow` —
`KeysetResolver` (active sort → ordered keyset columns + the cursor staleness
check), `KeysetColumn`, `InMemoryKeyset` (the forced NULL=largest order, the
lexicographic AFTER predicate) and `CursorTokenMinter` (JSON-safe coercion, the
forward/backward has-flag rules, token encoding). The copies had not drifted in
behaviour — only in namespace and in adapter-naming comments (Doctrine vs
Eloquent) — so the contract is really core's, and duplicating it invited exactly
the drift the classes exist to prevent. The four classes now live in core under
`haddowg\JsonApi\Collection\Keyset`: they are collection *execution* machinery
(the toolkit behind `WindowExecutor::runCursor()`'s probe/cursors closures, next
to `CursorCollectionResult`), not request/render-side pagination like the
paginators, windows and codec under `Pagination`.

Two deliberate reconciliations against the integration copies: adapter-specific
comment references (Doctrine/Eloquent class names, the bundle-only
`KeysetWhereBuilder`, the integrations' `CriteriaApplier`) were neutralised to
store-agnostic phrasing, taking the Laravel copy's already-neutral wording where
the two differed; and `KeysetResolver::resolve()` now takes the sort inputs
directly (`list<string> $requestedSort`, the declared `SortInterface` vocabulary,
the `SortDirective` default sort) instead of the integrations' `CollectionCriteria`
— that type is an integration SPI value core does not define, and the resolver
only ever read those three members off it. Behaviour is otherwise unchanged. The
integrations keep their local copies until each swaps to the core classes in a
follow-up.
