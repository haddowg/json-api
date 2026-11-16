# nullable() describes the read of a value no write can set, even beside guaranteed()

A read schema dropped `null` from any field declaring `guaranteed()`, so a member declared `guaranteed()->nullable()` inside a read-only object or list was typed non-null and a response carrying its `null` failed validation against the published document. On a field a write can set, `nullable()` says what a write may send and `guaranteed()` that the server fills a value in, so both hold. On a field no write can set, `nullable()` has no write to describe, so its only meaning is that the rendered value can be `null`, and the read schema now admits it. A nested member counts as written only by the writes that can set every value enclosing it: a member of a read-only `Obj` is no more writable than the `Obj`, and a member of a value only a create writes takes its read constraints and its `null` from the create alone.

## Considered options

- Letting `nullable()` win over `guaranteed()` on every field: it would widen the read type of a writable field whose `null` write the server replaces with a default, the case `guaranteed()` exists for.
- Treating `required()` on a member of a read-only value as no promise, as it is for a read-only attribute: a computed or read-only value's members are built by the server, so their `required()` describes the shape it renders, and an extracted value keeps projecting the shape it would with no hook.

## Consequences

A `component()` shape's body holds for every use of it, and one use may sit where no write reaches, so inside a named shape a `guaranteed()->nullable()` member admits `null` on read whichever field uses it. The body is projected from no enclosing value, so a shape used both inside a read-only value and as a writable attribute stays one component rather than failing as two shapes under one name.
