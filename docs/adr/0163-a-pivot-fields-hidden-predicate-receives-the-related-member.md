# A pivot field's hidden predicate receives the related member

A top-level field's `hidden(when:)` predicate receives the resource model, but a pivot field's had no defined subject, so the adapters disagreed: one evaluated only static `hidden()`, another passed the related member. A pivot field's predicate now receives the related member, the far-side resource whose linkage or resource object carries the `meta.pivot`, and `BelongsToMany::readablePivotFieldsFor($request, $relatedMember)` applies it together with `writeOnly(when:)`, so every host renders a member's pivot with one call. `readablePivotFields()` stays the request-independent superset the OpenAPI projection reads.

## Considered options

- The join row, by analogy with an `Obj` child whose predicate receives the stored object it reads from. The join row is a Doctrine association entity, an Eloquent pivot model or an array depending on the host, and not every host still holds it where it renders `meta.pivot`, so a predicate typed against it would only run on one adapter. The related member is what the response renders and is the object every host already has at that point.
- The parent resource. Every member of one relation would share it, so it can't hide a value for one member and not another, and a predicate that needs it can usually reach it from the member.
- A separate request-only `readablePivotFieldsFor($request)` beside a per-member method. Rendering always has a member, so a second method would only give hosts a way to skip `hidden(when:)`.
