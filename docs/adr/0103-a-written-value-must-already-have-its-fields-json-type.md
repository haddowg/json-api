# A written value must already have its field's JSON type

The field casts converted whatever a write sent, so `"yes"`, `1` and `"false"` all stored `true` in a `Boolean` and `42` was stored in a `Str`, while the write schema promised types the server never checked. Core now names each field's JSON type in one place, `ValueTypes`, which the projector also reads, and walks a write body for values without it at any depth, so each adapter refuses one with a single `422` at the value's pointer in place of whatever its other rules made of it. The check sits in validation rather than in the casts because the adapters already validate before hydrating and report every error at once. A list sent for an object stays with hydration's own `ATTRIBUTE_VALUE_INVALID`, since clients already handle that error.

## Consequences

A write that relied on the casts now fails: `"1"` or `1` for a boolean, a numeric string for an integer or number, a number or boolean for a string or date. Query-string input is unaffected, since it is always text and each filter's deserializer reads it.
