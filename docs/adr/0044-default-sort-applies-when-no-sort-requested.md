# A collection's default sort applies when the request sends no `?sort`

`AbstractResource::defaultSort()` returns a list of `SortDirective`s a data layer
applies to a collection **only when the request carries no `sort` parameter**; an
explicit `?sort=` overrides it entirely (the default is never appended to a
requested sort). We add this because `allSorts()` only governs which sorts are
*accepted* — an unsorted collection was returned in storage order, which also made
pagination non-deterministic, so a declared default order is a real correctness
win (mirroring Laravel JSON:API's `$defaultSort`). The directive shape — pairing a
`SortInterface` with a direction, most significant first — is exactly what a data
layer already builds for a requested sort, so the default flows through the same
sort handler with no new execution path; a default must therefore name a sort the
handler can execute. The lever lives on `AbstractResource` (not a serializer
interface) because applying a default to a collection is a data-layer concern, and
the directive shape is the same one the resource's sort vocabulary already speaks.
