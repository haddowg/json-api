# Related attributes are flattened through to-one chains, with declared eager loads

An attribute may be flattened `on()` a chain of to-one relations, read from and written onto the final related model, and the resource declares the deduplicated set of those chains as eager loads that a host loads without rendering. The chains are validated at boot (an unknown or to-many segment is a `\LogicException`) so an author mistake fails at deploy, not as a runtime `500`. A general `with()`/always-load declaration was considered and dropped: its remaining cases were niche, and it interacted badly with Relationship Queries and nested validation.

## Consequences

A null hop on write is a `422`; a flattened attribute never instantiates a related model.
