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
