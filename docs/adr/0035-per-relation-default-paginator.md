# Per-relation default paginator

A to-many relation can declare a default paginator for its related-collection
endpoint via `paginate()` (read back through `pagination()`, exposed on
`RelationInterface` so a host adapter can call it), mirroring the resource-level
`pagination()` default. A to-one relation has no collection and ignores it; the
host resolves the effective strategy for a related-collection request as
relation → related-resource → server default, calling `RelatedResponse::fromPage`
when a `page[…]` window applies. `paginate()` mutates and returns `$this`,
matching the relation builder's other fluent setters (the relation is a mutable
builder, not an immutable value object).
