# An equality filter matches a uuid value in either case

A uuid-shaped id renders in lower case, so a client copies that id into `filter[owner]` and
missed the row whose column keeps it in capitals, while the id filters already matched it.
An equality filter whose value is a uuid now matches either case, decided by the value's
shape since a plain column says nothing about holding an id, and a database adapter binds the
lower- and upper-case forms rather than lowering the column, so an index still serves the
query. Ordered and pattern operators keep comparing as they did, and a uuid whose letters mix
cases matches only as sent.
