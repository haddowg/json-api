# A traversal path is resolved against the relation graph by the data layer

A `WhereThrough` or `WhereThroughIn` path may end on a relationship, and the filter then compares the related resource's id, decoded through the related type's encoder. A filter declaration has no relation graph to tell a relationship from a field, so resolution is a separate step: a `ThroughPathResolver` matches each segment to a declared relation by its storage name and hands back a filter carrying the resolved `ThroughPath`, the decoding and the related id's format as default constraints. Core owns that step so the in-memory reference, Doctrine and Eloquent decode and validate identically, and each data layer runs it after matching the requested keys (and may run it at warm-up to fail fast), as does the OpenAPI projection. A path whose segments do not all match declared relations ends on a field and behaves as it always did, which keeps every existing `WhereThrough` unchanged; a polymorphic leaf is a `\LogicException` at resolution.

## Considered Options

- Resolving at build: a builder has no access to the related types, so it cannot know the leaf or its encoder.
- Letting each adapter read its own storage metadata for the leaf: Doctrine and Eloquent could both tell an association from a column, but neither knows the related type's id encoder, and the two would drift on validation.
- Matching segments by relation name as well as storage name: it would let a path name a relation whose storage column differs, but it could silently change what an existing field-ending path traverses.

## Consequences

A relationship leaf's default constraints are a resolved, not guessed, value type, so they sit outside the column fallback of [ADR 0055](0055-a-filter-value-defaults-to-the-type-of-the-field-it-targets.md) and, unlike it, validate as well as document. A filter that reaches a handler unresolved is read as ending on a field.
