# A host can supply a to-one target out of band

A to-one relation always read its related object off the parent, so a host that loads related rows through its own path could only change what core rendered by writing onto the parent, which breaks objects shared between parents and entities without setters. `ToOneRelationshipLinkageInterface` lets the host hand core the target per (parent, relation) instead: core uses it for the linkage `data`, for `included`, and for every hop of an `on()` attribute on read and write, and a supplied none renders as an empty relation (`data: null`, nothing included, a null hop) even when the parent holds an object. It extends `RelationshipLinkageInterface` and core checks for it on the object injected through `withRelationshipLinkage()`, so no public interface gains a method and a resolver that delegates `relationshipLinkage()` carries it without further work.

## Considered options

- A new method on `RelationshipLinkageInterface` or `SerializerResolverInterface`, which would break every implementer.
- A separate `Server` wither and an opt-in resolver interface like `ProvidesOutputTimezoneInterface`. A resolver decorator that forgot to implement it would quietly render the parent's object, so the seam rides on the accessor that already exists.

## Consequences

A `MorphTo` target picks its serializer by its own type, and one that matches no declared type renders as none. `RelationInterface::readValue()` is unchanged: an adapter calls it itself, for the related endpoint for instance, so the adapter already decides what it reads there.
