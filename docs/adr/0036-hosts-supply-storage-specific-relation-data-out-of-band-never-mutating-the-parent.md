# Hosts supply storage-specific relation data out of band, never by mutating the parent

When a host windows, narrows or preloads what a relation renders, it hands core the result through resolver seams injected on the `Server` (`RelationshipLinkageInterface`, its to-one extension `ToOneRelationshipLinkageInterface`, `RelationshipPaginationInterface`, `RelationshipCountInterface`, `ResourceLinkContributorInterface`) instead of writing onto the parent model. Writing back is destructive when two relations share a backing property or the parent is a shared in-memory object. Each seam defaults to reading the model as before, and new capabilities ride on the existing accessor (an `instanceof` check on the injected object) so no implemented interface gains a method.

## Consequences

Contributed resource links merge with author-wins precedence. Supplied linkage is used for linkage `data`, `included` and `on()` hops alike, and a supplied "none" renders an empty relation even when the parent holds an object.
