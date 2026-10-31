# A relation's security overrides its parent's, and a hidden relation reads as absent

A relation may declare read and mutate security that replaces the parent resource's gate for that relation, so a relation can be more or less permissive than its resource; `null` inherits. Core stores the declarations, the host evaluates them, and the projector reflects them. Core enforces them where it reaches relation data itself, through a host-supplied `RelationAuthorizerInterface`: a denied read keeps the relationship's links but drops its data, meta and includes per parent, and a denied flattened write is `403 ACCESS_DENIED`.

## Consequences

A relation hidden from the caller answers `404 RelationshipNotExists` at its endpoints, not `403`, so it looks the same there as it does in the resource.
