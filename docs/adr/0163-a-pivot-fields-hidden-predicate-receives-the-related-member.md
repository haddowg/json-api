# A pivot field's hidden predicate receives the related member

A pivot field's `hidden(when:)` predicate receives the far-side related resource whose linkage carries the `meta.pivot`, and `readablePivotFieldsFor($request, $relatedMember)` applies it, so every host renders a member's pivot the same way. The related member is the one object every host has at render time and it varies per member.

## Considered options

- The join row: a Doctrine entity, an Eloquent pivot or an array depending on the host, and not always held where `meta.pivot` renders.
- The parent resource: shared by every member, so it cannot hide a value for one member and not another.
