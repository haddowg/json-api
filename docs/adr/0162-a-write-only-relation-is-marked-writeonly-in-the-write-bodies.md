# A write-only relation is marked `writeOnly` in the write bodies

A client generator could already tell a write-only relation apart, since it appears in the create and update bodies' `relationships` and not in the response, but only by comparing two schemas and treating an absence as meaning something. The `relationships.<rel>` property of each create, update and atomic body now carries the OAS 3.1 `writeOnly: true` keyword beside its `$ref` for a relation whose metadata reports `DeclaresWriteOnlyRelation::isWriteOnly()`, so a generator reads it where the relation is declared. A relation write-only only by predicate stays unmarked and readable, the superset ADR 0153 chose.

## Considered options

- A vendor extension such as `x-write-only`. The standard keyword means exactly this (sent in a request, never in a response), and JSON Schema 2020-12 allows it next to a `$ref`.
- Mark the relationship object component instead. A non-pivot relation's component is shared with the read resource, and the keyword describes a property, not a schema.
