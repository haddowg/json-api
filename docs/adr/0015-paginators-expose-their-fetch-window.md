# Count-based paginators expose their fetch window for data-layer push-down

`PaginatorInterface::paginate()` takes pre-windowed items — page value objects
never slice — but the strategy's window derivation (`page[…]` keys, defaults,
page-number → offset arithmetic) was private to each `paginate()`
implementation, so a data layer could not learn the window *before* fetching to
push it down to its store (SQL `LIMIT`/`OFFSET`); the Symfony bundle's Doctrine
provider forced the seam. `PaginatorInterface` gains
`window(JsonApiRequestInterface): WindowInterface`, with `OffsetWindow{offset,
limit}` as the shape every count-based strategy reduces to (concrete paginators
covariantly narrow to it). The return type is the polymorphic `WindowInterface`
— not `OffsetWindow` — so a future cursor-capable pagination contract can hand
a cursor-shaped window through the same seam; data layers narrow on the
concrete window types they can execute. Extends the metadata-in-core,
execution-in-adapters split of
[ADR 0007](0007-metadata-in-core-execution-in-adapters.md) to pagination.
