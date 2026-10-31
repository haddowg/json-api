# Resource unions are discriminated by type and included is typed

A polymorphic relation's linkage identifier and related resource were an `anyOf` of the member components, and a compound document's `included` items were an untyped `{type, id}`, so a code generator could not narrow a union member or type an included resource without guessing. Both are now a `oneOf` with a `type` discriminator mapping each type to its `<Type>ResourceIdentifier` or `<Type>Resource`, which is valid because every member declares `type` as a `const`, and `included` reaches exactly the types the operation's `include` enum reaches, so the two can never disagree. An operation whose paths reach nothing omits `included` rather than offering an empty array, since no response it serves can carry one.

## Consequences

The tier is `DISCRIMINATED_UNIONS`, contract 19, stamped on a document with a polymorphic relation or a typed `included`. Nearly every document has one, and the baseline witness moved with it, so `BASELINE` rose to 19 alongside `CONTRACT` and the scoped constant names the structure rather than narrowing who sees it. The atomic operation and result `data` unions stay `anyOf`: a type's resource and its identifier there share one `type` value, so a `type` discriminator cannot tell them apart.
