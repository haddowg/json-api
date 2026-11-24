# A foreign key may name a MorphTo identified by columns, never its id field

A `HasMany` or `HasOne` whose related rows point back through a polymorphic relation names
that `MorphTo` as its foreign key, and a data layer compares both of the fields
`identifiedByColumns()` declares: the type field against the parent type's stored values and
the id field against the parents' keys. Naming the bare id field instead is refused at
warm-up, because that field holds the ids of several types and the relation would read rows
that point at another type with the same id. Refusing it breaks an application that boots
with such a declaration today, which is the point: those reads were already wrong.
