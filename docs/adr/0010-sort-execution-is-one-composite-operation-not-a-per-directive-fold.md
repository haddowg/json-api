# Sort execution is one composite operation, not a per-directive fold

`SortHandlerInterface::apply()` receives the whole requested order (`list<SortDirective>`, most significant first) in one call rather than one directive at a time like filters. Sorting does not compose commutatively and the correct fold differs per data layer (SQL appends `ORDER BY` in significance order, an in-memory stable re-sort makes the last applied key primary), so a per-directive fold produced different primary keys on different providers.
