# A response linkage identifier requires its pivot

A host renders `meta.pivot` on every member of a pivot relation's linkage whenever the
linkage renders, `{}` when the request hides every pivot field, so the projection lists `meta`
and `pivot` in `required` on each response identifier, and a generated client reads
`member.meta.pivot` without guarding against either being absent. The identifiers a request
sends keep both optional, since a client attaching a member need not send pivot values. The
change raises `BASELINE` with `CONTRACT` rather than adding a scoped tier, because pivot
relations already belong to the baseline shape the baseline witness holds. A pivot relation's
relationship object example carries the pivot values the operation examples use, so it stays
valid against the identifier it describes.

## Amendment: atomic results carry the same linkage

A relationship operation in an atomic batch returns the linkage its relationship endpoint
returns, so `AtomicResult` types it the same way, one `anyOf` branch per relation that types
its identifiers' `meta`. A result does not say which operation produced it, so a branch per
relation is as close as the schema can get, and a type only those relations link to leaves
the shared identifier union so its members cannot match without their `meta.pivot`. It rides
on the same baseline contract rather than a tier of its own.
