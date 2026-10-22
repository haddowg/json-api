# Object members never encode as a JSON array

`json_encode()` writes an empty or list-shaped PHP array as a JSON array, so an empty
`ArrayHash`, an `Obj`/`Map` with nothing to render, or an empty links container came out
as `[]` where the spec and the projected schema want an object. A map attribute now hands
the encoder a `\stdClass` when its array is empty or list-shaped (an `ArrayHash` holding
`['a']` renders `{"0": "a"}`), decided per field rather than with `JSON_FORCE_OBJECT`,
which would also turn real lists like `data` and `included` into objects. Optional object
members with nothing in them (`links`, an error's `source`, `jsonapi`) are left out
instead of rendered as `{}`, because an absent member survives a decode-to-array and
re-encode round trip that would turn `{}` back into `[]`.
