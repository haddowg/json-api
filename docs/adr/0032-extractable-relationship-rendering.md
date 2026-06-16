# Extractable relationship rendering

`RelationInterface::buildRelationship()` is self-contained — it needs only the
model, the request and a `SerializerResolverInterface`, none of which is owned by
`AbstractResource` — so we extract the `getRelationships()` callable-map assembly
into a `RendersRelationsTrait` and add a `SerializerResolverAwareInterface` plus
resolver injection for any resolved serializer (not only an `AbstractResource`).
A custom or standalone serializer can now render relationships from a standalone
list of relations without extending `AbstractResource`; the base is refactored
onto both the trait and the interface with identical behaviour, and the registry
injects itself into any serializer that opts in (a serializer that does not is
left untouched — backward compatible).
