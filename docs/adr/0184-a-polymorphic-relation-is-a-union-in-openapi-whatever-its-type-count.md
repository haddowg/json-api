# A polymorphic relation is a union in OpenAPI whatever its type count

Polymorphism is decided by declaration (`MorphTo`/`MorphToMany` implement `PolymorphicRelationInterface`, surfaced through opt-in metadata), not by counting related types, so a polymorphic relation over one type still projects as a union. Deciding by count made adding a second type change every generated input that carried the relation and drop its filter, sort and page parameters.
