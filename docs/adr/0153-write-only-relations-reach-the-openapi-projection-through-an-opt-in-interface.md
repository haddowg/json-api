# Write-only relations reach the OpenAPI projection through an opt-in interface

The projector needs to know a relation is write-only to drop it from the read schema and its relationship `GET`, and `RelationMetadataInterface` has no such accessor. Adding one after 1.0 would break every adapter implementing it, so `DeclaresWriteOnlyRelation` extends it instead, as `DeclaresOperationExtensions` does for types. Only an unconditional `writeOnly()` reports `true`, so a predicate-gated relation stays in the superset schema the way a predicate-gated attribute does.
