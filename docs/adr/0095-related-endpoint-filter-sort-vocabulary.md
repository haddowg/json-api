# Project the merged filter/sort vocabulary on related and relationship endpoints

The related/relationship operation projection built its `filter[]` / `sort`
parameters from `RelationMetadataInterface::filters()` / `sorts()` alone — only
the **relation-scoped** filters/sorts. But the runtime's effective vocabulary on a
related endpoint is the **related resource's own** filters/sorts **merged with**
the relation's (the relation winning on a key collision). Three under-projections
resulted:

- the to-many related endpoint advertised only the relation's `filter[]`/`sort`,
  not the related resource's own (`GET /albums/1/tracks` honours the track
  resource's `filter[explicit]` and `sort=trackNumber`, neither projected);
- the **to-one** related endpoint projected no `filter[]` at all, though a
  relation filter that excludes the target nulls the linkage (and is validated →
  `400`);
- the **to-one** relationship (linkage) endpoint projected no parameters at all,
  though it honours the same `filter[]`.

The projector now resolves the related type's metadata for a **monomorphic**
relation and merges `relatedType.filters() ⊕ relation.filters()` (and the sorts),
projecting it on the to-many related endpoint (filter + sort), the to-one related
endpoint (filter only — a to-one `400`s on `sort`/`page`) and the to-one
relationship endpoint (filter). A **polymorphic** relation has no shared
vocabulary, so only its own (typically empty) set applies. A to-many relationship
(linkage) endpoint returns the whole linkage and takes no query filters, so it
projects none.
