# An identified MorphToMany renders linkage from its stored pairs, narrowed by the members a host supplies

`MorphToMany::identifiedBy()` reads the members' `[type, id]` pairs off the parent, so the linkage of a join table spanning several types renders in pair order without loading a member, and like the `MorphTo` counterpart (ADR 0078) it is never load-aware and the pair's type picks the serializer. A host that has to hide members supplies the ones that survive as `IdentifiedMember` values through the existing to-many linkage seam, each with its loaded target when it has one, so core stays storage-agnostic and needs no second seam.

## Considered options

- Supplying the surviving members as plain objects: every member would need a load just to render an identifier, and one class serving several types couldn't say which type it is.
- A dedicated seam for identified to-many relations: it would duplicate the to-many seam's lookup and precedence for a difference that fits in the value the seam already returns.

## Amendment: the relationship endpoint pages the pairs

An identified `MorphToMany`'s relationship endpoint pages its pairs exactly as its related endpoint does, since the pairs give it a stable order and an exact total that other polymorphic to-many relations lack. The projector gives that one operation the related endpoint's `page` and `withCount` parameters, learning about the relation from the opt-in `DeclaresIdentifiedMembers` metadata, and every other polymorphic relationship endpoint keeps taking none.
