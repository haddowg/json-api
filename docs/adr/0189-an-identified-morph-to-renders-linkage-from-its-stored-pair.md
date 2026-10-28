# An identified MorphTo renders its linkage from its stored pair and is never load-aware

A `MorphTo` picked its serializer from the target's own type, so rendering its linkage meant loading the target, one query per parent when a host stores the morph as a type and id column pair. `identifiedBy()` lets the relation read `[type, id]` off the parent instead. The linkage renders from that pair, its id run through the target type's id encoder, and the target is read only when the relationship is included. Because the pair never needs a load, an identified relation opts out of the load-aware deferral policy outright: it ignores the injected `RelationshipLoadStateInterface` and reports `emitsDataOnlyWhenLoaded()` as false, so it always emits `data`, the way an eager `BelongsTo` does.

## Considered options

- Keep consulting the load-state predicate and defer when it reports the target unloaded. The predicate answers whether the *target* is in memory, which says nothing about whether the pair is, so it would hide linkage that costs nothing to render.
- Have core resolve the target from the pair. Core has no store to look an id up in, so loading stays with the host, which gets the pairs of a whole page grouped by type from `MorphTo::storedIdentifiers()` and hands the loaded targets back through `ToOneRelationshipLinkageInterface`, which core consults before the pair.

## Consequences

The pair is checked against the declared types on every read, and an undeclared type is a `\LogicException`, a 500, naming the class that declared the closure, the relation and the type. An identified relation cannot also declare `identifierMeta()`, whose resolver receives the target. A pair renders only `type` and `id`, without the target serializer's identifier `meta`.

## Amendment: the pair's type picks the target's serializer

The pair is authoritative for the identity, so the target, whether the host supplied it through `ToOneRelationshipLinkage::to()` or the relation read it off the parent, renders into `included` with the serializer of the pair's type. Resolving it from the object instead picked the first declared type whose serializer claimed it, which put every row of a class serving several types under one of them. The target only supplies attributes and relationships. It is still left out when the pair type's serializer reports another type for it, a supplied `none()` still empties the relation, and a target supplied for an empty pair renders nothing, because there is no pair to render it under.
