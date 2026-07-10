# Immutable value objects, with deliberate carve-outs

The default for every data type is a `final readonly` value object: promoted
public properties, no getters, named constructors for alternate forms, and no
mutating setters. Two carve-outs are intentional and a reader should recognise
them as such rather than "fixing" them:

1. **The request and response layers are not `readonly`.** They expose `with…()`
   methods implemented by clone-then-assign, because the wither pattern and the
   lazy per-request parse caches both forbid readonly properties. Immutability
   still holds at the use site — the wrapped state is never mutated in place.
2. **Fluent field / filter *builders* are mutable by design**, so a declaration
   reads as a single chained expression rather than a rebuilt value at each step.
   The builder is no longer *itself* the value object, though: it terminates in a
   `build()` that freezes it into a `final readonly` field / filter value object —
   so this carve-out is confined to the authoring surface, and the thing the engine
   consumes is immutable like everything else. See
   [ADR 0132](0132-builder-produces-readonly-value-object.md).
