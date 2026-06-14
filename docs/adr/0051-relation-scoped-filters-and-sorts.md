# A relation may declare filters and sorts scoped to its related collection

A `RelationInterface` (and `AbstractRelation`) now carries `filters()` and
`sorts()` readers — the same `FilterInterface` / `SortInterface` metadata value
objects a resource exposes — declared through the fluent `withFilters(...)` /
`withSorts(...)` builders (default `[]`, append on repeat, return `static` like
the relation's other setters, e.g. `paginate()`). Core only lets a relation
*carry* these; it does not apply them. The host (the Symfony bundle) merges a
relation's `filters()`/`sorts()` with the related resource's own vocabulary when
parsing a related-collection request's `?filter`/`?sort`, and the adapter's
existing filter/sort handlers execute them unchanged.

The point is *scoping*. A filter or sort declared on the related **resource** is
exposed everywhere that type is listed (`/tracks` **and**
`/playlists/1/tracks`). Declaring it on the **relation** scopes it to that one
related-collection endpoint — the natural home for a contextual filter/sort
(ordering a playlist's tracks by their in-playlist position; a filter only
meaningful when listing a user's posts). On a key clash with the related
resource's own vocabulary the relation's declaration is the more specific scope
and wins. The metadata-only declaration mirrors the resource's
filter/sort split, so the seam stays uniform and no execution path changes in
core. (Gap #31 plus the symmetric sort half.)
