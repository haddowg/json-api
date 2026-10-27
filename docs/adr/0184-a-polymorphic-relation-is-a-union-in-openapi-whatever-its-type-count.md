# A polymorphic relation is a union in OpenAPI whatever its type count

The projector used to decide polymorphism by counting a relation's related types, so a `MorphTo` over one type was projected exactly as a `BelongsTo`, and a code generator could not tell them apart. Adding a second type then changed every generated input that carried the relation, from a single identifier to a union, and dropped the related type's filter, sort and page parameters from its endpoints. A relation polymorphic by declaration is now projected as a union even with one member, so the shape is stable from the first type. It is declared through an opt-in `DeclaresPolymorphicRelation` on the relation metadata, so existing adapters keep compiling and keep their documents, and core's `MorphTo` and `MorphToMany` implement a marker, `PolymorphicRelationInterface`, that adapters read to answer it.

## Consequences

A document containing such a relation carries the scoped contract tier `SINGLE_TARGET_POLYMORPHISM`, 17, and every other document is unchanged. The related and relationship endpoints of a single-type polymorphic relation advertise no parameters from the related type, while an adapter that decides by the count still honours them at runtime, so until the adapters decide by the declaration too the document describes less than they accept.
