# Relationship factories require the related resource type

A relationship is meaningless without a related resource type: its linkage
identifier objects need a `type` member, and its `related` / `relationship`
endpoints need that type to resolve a serializer — a type-less relation could
only ever emit bare links pointing at endpoints that cannot render. So the type
is now a **mandatory factory argument**, not an optional fluent setter: a
monomorphic relation takes a single type (`BelongsTo::make('owner', 'users')`),
a polymorphic one takes a non-empty list (`MorphTo::make('commentable',
['posts', 'videos'])`). The old `type()` / `types()` setters are removed.

To make the argument a *compile-time* requirement we relocated the
single-argument `make(string $name)` factory off the relation inheritance path:
attributes now extend a new `AbstractAttribute` (which carries it), while
`AbstractRelation` extends `AbstractField` directly and each relation family
declares its own `make()` via `DeclaresMonomorphicType` (native `string $type`)
or `DeclaresPolymorphicTypes` (native `array $types`). This is forced by PHP's
LSP rules — a child static method may neither add a required parameter to an
inherited signature nor narrow a `string|array` union on an override — so a
single typed-and-required `make` could not live on a shared ancestor. The native
per-family signatures give the strongest guarantee: omitting the type, or passing
the wrong shape (a list to a monomorphic relation, a string to a polymorphic
one), is a static-analysis error, and an empty type/list is rejected at
construction. A directly-constructed relation (bypassing `make()`) is unaffected:
the render paths still guard on a present type, so it degrades to links-only
rather than erroring.
