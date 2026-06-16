# Write-only attributes are accepted on write but never rendered

A field could already be `readOnly()` — rendered but never hydrated — but there
was no inverse: an attribute **accepted on write yet never echoed back**, which is
exactly what a write-once secret (a password, an API token, a credential) needs.
We add the mirror of the read-only pair on `AbstractField`: a `writeOnly()`
builder and an `isWriteOnly(): bool` reader (default `false`) declared alongside
`readOnly()` / `isReadOnly()` and surfaced on `FieldInterface`.

A write-only field is **skipped in the attribute render** — dropped in
`AbstractResource::getAttributes()` (and in `Map::serialize()` for a nested child)
**before** sparse-fieldset filtering runs, so it never enters the candidate map. It
therefore appears on no read representation (single, collection, included, related)
and a `fields[type]` parameter explicitly naming it cannot resurrect it. On the
**hydrate** side it is the exact inverse of read-only: read-only fields are skipped
in `hydrateAttributes()`, write-only fields are not, so a write-only field is
accepted on both create and update. Its declared constraints are returned from
`constraints()` unchanged, so a framework validator (the Symfony bundle's bridge)
still validates it on write — a write-only password can be `required()` /
`minLength(...)`.

Declaring a field both `writeOnly()` and `readOnly()` is contradictory (it could be
neither read nor written), so it is a `\LogicException` at declaration time,
order-independent: `writeOnly()` rejects an already read-only field and the three
read-only builders reject an already write-only field. This is the cleanest signal —
a silent "read-only wins" no-op would hide an author mistake.

A future OpenAPI generator reads `isWriteOnly()` to place the member in the request
schema only; that schema work is out of scope here and this ADR records only the
field-model semantics it will rely on.
