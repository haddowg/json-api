# A read attribute admits null unless the server guarantees a value

The read schema typed every attribute without `null` unless a write declared `nullable()`, but an optional field a create leaves out is stored and rendered as `null`, so a client generated from it broke on real responses. A read attribute now admits `null` unless a value is guaranteed: every create had to supply it (an unconditional `Required` on create, on a field a create can write, with no hook or `on()` chain in between), the `serializeUsing()` / `extractUsing()` hook producing it declares a non-null return type, or the author asserted it with the new `guaranteed()`. Core can't see a column default or whether the server always fills a read-only field, so those stay nullable until asserted.

## Considered options

- Treat a read-only field as guaranteed. `createdAt` usually is, but a read-only `approvedAt` is null until something approves it, and core can't tell the two apart. Overstating breaks clients; understating only costs a null check, so the author asserts the first case.
- Trust an untyped hook on a required field. The hook replaces the stored value and can return anything, so only its declared return type counts.
- Let `nullable()` beat `guaranteed()`. A write that accepts `null` and a fill hook that swaps it for a default is the case `guaranteed()` exists for, so the assertion describes the rendered value and wins on read. Request bodies keep `nullable()`.

## Consequences

Presence stays separate. A response renders every visible attribute, `null` or not, so the read `required` list is unchanged except that a `sparseByDefault()` member leaves it: it is absent unless a `fields[<type>]` names it. A to-one relationship's `data` was already nullable.
