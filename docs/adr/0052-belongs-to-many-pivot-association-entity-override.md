# A BelongsToMany relation may name the association entity backing its pivot

`BelongsToMany` already declares its pivot (join-table) fields via
`fields([...])` with a `pivotFields()` reader — declare-only metadata core
carries but never validates, consumed by the Symfony bundle's Doctrine adapter.
We add a matching `through(?string $associationEntity)` builder and a
`pivotThrough(): ?string` reader for the same purpose: an **opaque, declare-only**
class-string naming the Doctrine association entity that backs the pivot.

The hard storage fact driving this: a plain `#[ORM\ManyToMany]` join table holds
only the two foreign keys, so pivot columns can only exist when the join is
modelled as an **association entity**. The bundle auto-detects that entity from
the parent's Doctrine metadata, but detection is ambiguous when the parent has
more than one candidate to-many association. `through()` is the override the
relation author uses to disambiguate. Core mirrors `fields()`: it stores the
value and hands it back untouched, never interpreting it, so core stays
storage-agnostic — the class-string is meaningful only to the Doctrine host.
