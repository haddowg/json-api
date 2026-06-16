# WhereThrough traverses a dotted relationship path as EXISTS-ANY

`WhereHas` could only test a relationship's *presence*; constraining a row by a
*related attribute* (`filter[author.name]=Ada`, or the multi-hop
`author.company.name`) had no portable vocabulary. We add a `WhereThrough` filter
VO whose dotted `path` is a chain of relationship hops ending in an attribute,
matched as an **EXISTS-ANY** semi-join: a to-one or a to-many hop translate
identically ("there exists a … whose …"), so a row matches when *any* value
reachable along the path satisfies the leaf comparison. The wire key is the path
by default (`WhereThrough::make('author.name')` → `filter[author.name]`) with a
named-key override (`make('topAuthor', 'author.name')`); both positional slots are
taken so the operator is the fluent `->operator()` setter (default `=`, the same
vocabulary as `Where`), and `->constrain()` (via `HasValueConstraints`) declares
value constraints an adapter validates before the data layer.

Both the in-memory witness and a database adapter implement this as **one shared
traversal**, and `WhereHas`/`WhereDoesntHave` are **folded onto it** as the
degenerate length-1 path with no leaf predicate — one code path, three behaviours
— while keeping both declarations. In `ArrayFilterHandler` the traversal walks the
path with `Accessor`, fans out across every to-many hop, and matches if any leaf
value satisfies the operator (the `Where` comparison body is extracted to a shared
`compare()` so a column and a leaf stay byte-for-byte equivalent); the existence
test for `WhereHas` is the same walk with a `null` leaf, "reaches at least one
present value". This is deliberately an EXISTS-ANY semi-join, never a fetch-join: a
database adapter renders it as a correlated `EXISTS` subquery, so it neither
hydrates the relation, leaks the constraint into a rendered to-many, nor multiplies
rows (which would force `DISTINCT` and break `LIMIT`/`OFFSET` pagination). Sort
traversal (`sort=author.name`) is out of scope — ordering by a related column needs
a join, a separate concern.
