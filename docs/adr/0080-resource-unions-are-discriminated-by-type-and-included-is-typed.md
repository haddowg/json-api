# Resource unions are discriminated by type and included is typed

A polymorphic relation's linkage identifier and related resource were an `anyOf` of the member components, and a compound document's `included` items were an untyped `{type, id}`, so a code generator could not narrow a union member or type an included resource without guessing. Both are now a `oneOf` with a `type` discriminator mapping each type to its `<Type>ResourceIdentifier` or `<Type>Resource`, which is valid because every member declares `type` as a `const`, and `included` reaches exactly the types the operation's `include` enum reaches, so the two can never disagree. An operation whose paths reach nothing omits `included` rather than offering an empty array, since no response it serves can carry one.

## Consequences

The tier is `DISCRIMINATED_UNIONS`, contract 19, stamped on a document with a polymorphic relation or a typed `included`. Nearly every document has one, and the baseline witness moved with it, so `BASELINE` rose to 19 alongside `CONTRACT` and the scoped constant names the structure rather than narrowing who sees it. The atomic operation is split into one component per kind, add, update and remove for a resource and for a relationship, so each kind's `data` is a single discriminated `oneOf`. The `op` const and a target naming a relationship, or not, keep the kinds exclusive, so the operation itself is a plain `oneOf` with no discriminator.

## Considered options

- An add or update carrying `<Type>Resource`, as first proposed: it requires `id` and the read-only attributes, so it rejects a create with a server-assigned id. They carry `<Type>AtomicAdd` and `<Type>AtomicUpdate`, which already declare `type` as a `const`.
- A relationship operation carrying `<Type>ResourceIdentifier`: it requires `id`, but a batch may name a resource by `lid`. A per-type `<Type>AtomicIdentifier` takes exactly one of `id` or `lid`, and the relationships inside an atomic add or update use it too.
- A `oneOf` between a result's resource and its linkage: a resource with no attributes also validates as an identifier, so that choice is an `anyOf`, while each side is a discriminated `oneOf`.
