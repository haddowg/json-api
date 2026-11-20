# A serialize hook receives the nested fieldset as extract hooks do

A `serializeUsing()` hook on a field whose keys a request can select built the whole value and had it trimmed afterwards, so a hook that loads its value piece by piece read every key on every narrowed request. It now receives the `NestedFieldset` as a fourth argument, `null` when the request takes the whole attribute, as `extractUsing()` and `computedUsing()` do since ADR 0137, and core still trims what it returns so a hook that ignores the argument renders the same members.
