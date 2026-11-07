# Declared objects are closed

A resource's `attributes` object and every nested object it declares (an `Obj`, a `Map`, a `OneOf` branch, a structured list's element) project with `additionalProperties: false`. Their members are fully declared, so a member they do not name is a client mistake: an open schema accepted it, the hydrator then dropped it, and the client never learned that a misspelt key went nowhere. Closing them also makes a `oneOf` over object branches exact, since a key outside a branch now rules that branch out.

## Consequences

A hook that produces the value in the direction projected owns its shape, so the object stays open there: `serializeUsing()` or `extractUsing()` on a read, `fillUsing()` or `deserializeUsing()` on a write. A standalone serializer with no field inventory keeps an open attributes object too, since nothing declares what it renders. Every document moves, so `BASELINE` rises with `CONTRACT`. Hydration refuses an undeclared member to match, before anything is written, with a `400` at `/data/attributes/<name>` or `/data/attributes/<field>/<key>`, so every adapter that hydrates through a resource enforces it; a declared member that is read-only for the caller is still ignored rather than rejected, so a client echoing back a fetched resource keeps working even though the write schema leaves that member out.
