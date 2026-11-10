# An enum field hydrates its case at the top and its backing value when nested

A field declared with `enum()`, or with `in()` over one backed enum's cases, used to hand its setter or property the raw backing value, so an entity typed with the enum threw a `TypeError` on every write and each such field needed an extract and fill hook pair to convert. Writes now hydrate the value as the case after validation, and reads render a case as its backing value, so the wire is unchanged and the projected documents with it. A value nested in an `Obj`, a `OneOf` variant, a structured list element or a list of unions keeps the backing value, because a data layer keeps those in a JSON column, the same rule that stores a nested date as a string; a `Union` stores its case's backing value through `toStoredForm()` and reads it back as the case through `fromStoredForm()`.

## Considered Options

Converting only when the model's property or setter declares the enum type was rejected: core would have to reflect on every model shape, arrays and Eloquent attributes among them, and the same declaration would hydrate differently across data layers. Converting nested values too was rejected, since a JSON column then holds an object only `json_encode` knows how to write and reads back as a string, so a model would see both forms.

## Consequences

An application whose property or setter takes the string, or whose Eloquent attribute has no enum cast, now receives the case. It keeps the string with a `deserializeUsing()` hook, which replaces the conversion, or reads `->value` in a `fillUsing()` hook, which receives the case. A `castWireValue()` returns the case too, so a pivot field declared with `enum()` writes the case onto its link row. A value outside the enum that reaches hydration without validation is refused with `ATTRIBUTE_VALUE_INVALID`, and a value of the wrong JSON type is left for the validator, as [ADR 0103](0103-a-written-value-must-already-have-its-fields-json-type.md) has it. Filters are untouched and keep binding the backing scalar. `enum()` refuses a pure enum when declared, since its cases have no value a request can carry.
