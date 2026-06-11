# Relation serializer resolution

`RelationInterface::resolveSerializer()` makes a relation the authority for
selecting the serializer that renders a related object as a full resource: the
single declared type when the relation is monomorphic, and — for a polymorphic
(`MorphTo`) relation — the declared type whose serializer reports the related
object's own type via `SerializerInterface::getType()`. Implemented once on
`AbstractRelation` (every relation extends it), it lets both `MorphTo`'s linkage
build and an integration host's related-resource endpoint share one resolution
rule instead of duplicating the per-object discrimination loop. A null related
value has no object to discriminate against, so the first declared, registered
serializer is returned (the caller renders `data: null`); `null` is returned only
when the relation declares no registered type or, polymorphically, none matches
the object.

The contract this relies on: a polymorphic family's serializers must return the
related object's true type from `getType()` (the default static `getType()` on
`AbstractResource` is correct for a monomorphic resource, where the related type
is fixed).
