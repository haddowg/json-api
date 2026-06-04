# Sort execution is one composite operation, not a per-directive fold

`SortHandlerInterface` originally mirrored `FilterHandlerInterface`'s
per-value-object `apply()` shape, but sorting — unlike conjunctive filtering —
does not compose commutatively, and the correct way to fold keys differs per
data layer: SQL appends `ORDER BY` terms in significance order, while an
in-memory stable re-sort makes the *last* applied key primary (the
significance-order fold the Symfony bundle's shared applier used therefore gave
the in-memory reference handler the wrong primary key — a provider-divergence
bug its conformance role exists to prevent). The handler now receives the full
requested order in one call — `apply(list<SortDirective> $sorts, $query)`, most
significant first — so each adapter composes natively (a single cascading
comparator in memory, sequential `addOrderBy` in SQL) and the request's first
sort field is the primary key everywhere, as the spec requires.
