# A pivot has separate response and request schemas

A `belongsToMany` relation's `meta.pivot` was one schema shared by the rendered linkage and the request bodies, projected in the update context, so it could not say a read-only field is always rendered or that an optional one comes back `null` without demanding read-only fields from every request (the gap ADR 0157 left open). It is now two components: `<Type><Rel>Pivot`, the response shape, which follows the read rules an attribute does (read-only fields in, write-only ones out, `required` lists every field rendered on every response, `null` admitted unless guaranteed per ADR 0156), and `<Type><Rel>WritePivot`, the request shape, which keeps the old write rules but leaves out a field no write can set instead of marking it `readOnly`. The write side needs its own linkage to reach it, so a pivot relation also gets `<Type><Rel>WriteRelationship` for the create, update and atomic bodies and `<Type><Rel>RelationshipRequest` for its relationship mutations, while the echoed `200` keeps `<Type><Rel>RelationshipDocument`.

## Considered options

- Split every relation the same way. Only a pivot changes between the two directions, so a relation without one keeps its single relationship object and document, and a generator following `$ref`s sees no difference.
- Mark read-only pivot fields `readOnly` in a single schema and list them in `required`. Many generators ignore `readOnly` when building a request type, so it would have forced the fields into every attach.
