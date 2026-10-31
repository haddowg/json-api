# Relationship-path filters match as an EXISTS-ANY semi-join

`WhereThrough` filters by an attribute reached through a dotted relationship path, and matches when any value along the path satisfies the comparison, with to-one and to-many hops treated alike. It is deliberately a semi-join (a correlated `EXISTS` in SQL), never a fetch-join, so it neither hydrates the relation, leaks the constraint into a rendered to-many, nor multiplies rows and breaks `LIMIT`/`OFFSET`. `WhereHas`/`WhereDoesntHave` are the same traversal with no leaf comparison, so they also ignore their request value.
