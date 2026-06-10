# Relationship-existence filters ignore their request value

`WhereHas` / `WhereDoesntHave` are existence predicates over a named relationship,
not value comparisons: the reference `ArrayFilterHandler` reads the related value
off the model (via `Accessor`) and matches on **presence alone** — a non-empty
array/`Countable`/`Traversable`, or a non-null to-one — ignoring whatever the
client sent as the `filter[...]` value. We pin this here because a filter that
discards its request value is surprising, and database adapters (Doctrine) must
mirror the same emptiness semantics (empty collection and null to-one both count
as "doesn't have") so the in-memory and ORM providers stay observationally
identical.
