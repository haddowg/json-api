# Polymorphic to-many relationships

A polymorphic to-many relationship (`MorphToMany`) renders its mixed-type members
by binding a `PolymorphicSerializer` — a `SerializerInterface` decorator that, for
each object, resolves the member's real serializer (via the relation's
`resolveSerializer()`, matching the member's own type against a declared one) and
delegates every method to it. Because the transformer already drives each to-many
member's identifier and included resource through the single serializer set on the
relationship, this one decorator gives every member its correct `type` / `id` /
attributes — so linkage, `?include`, and a host's related-resource collection all
work with **no change** to the transformer, `ToManyRelationship`, or
`RelatedResponse`; the decorator is precisely what avoids those changes.

The contract this relies on (the existing `MorphTo` contract): a polymorphic
family's serializers must return the member's true type from `getType()`.
