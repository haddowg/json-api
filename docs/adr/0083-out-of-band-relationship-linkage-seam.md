# Out-of-band relationship-linkage seam

A rendered to-many relationship's linkage `data` is read off the parent model's
backing property — `relatedValue()` → `Accessor::get($model, column)`. A host that
windows a relationship (the Relationship Queries profile's page-1 window) therefore
had to **write the windowed page back onto that property** so core reads it as the
linkage. That write is destructive: when two relations share one backing property
(a `withData()` `comments` and a lazy `lockedComments` over the same association),
core reads BOTH off the same property, so the windowed relation's filtered page
overwrites the sibling's full set — the sibling renders the windowed relation's
data (and, under a lazy load-state predicate, the write flips it to "loaded" so it
emits a `data` member the lazy default would have omitted).

`RelationshipLinkageInterface` (with the small `RelationshipLinkage` VO) is the
storage-aware seam, injected through `SerializerResolverInterface` and threaded by
`Server::withRelationshipLinkage()`, mirroring the existing
`RelationshipPaginationInterface` / `RelationshipCountInterface` /
`RelationshipLoadStateInterface` exactly (same null-default, same per-(parent,
relation) keying). When it returns a non-null `RelationshipLinkage` for a to-many,
`buildToMany()` uses that data as the relationship's linkage **eagerly** (always a
`data` member), consulted **before** the lazy-defer/eager branch; when it returns
`null` the relation falls back to reading the value off the model exactly as before.
So a host supplies a windowed page **out-of-band** and never mutates the parent — a
column-sharing bystander reads its own untouched property.

Core ships no implementation: with none injected (standalone library) linkage is
always read off the model, exactly as before this seam existed. **Breaking** only in
the additive `SerializerResolverInterface::relationshipLinkage()` method (every
in-tree implementor — `Server`, the stub doubles — gains the accessor); pre-1.0 a
minor bump (`feat!`). The companion bundle change (bundle ADR 0086) fills the seam
from the windowing batcher and stops writing the page onto the parent.
