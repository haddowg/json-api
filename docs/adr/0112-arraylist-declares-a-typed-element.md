# ArrayList declares a typed element, defaulting to string

An `ArrayList` attribute projected its OpenAPI `items` as a typeless schema, so a
constraint-only list (e.g. `each(MinLength(1))`) emitted `items: {minLength: 1}` with no
`type` — which a code generator degrades to `unknown[]`, leaving every list attribute
untyped for consumers.

`ArrayList` now carries an element type — `of('string'|'integer'|'number'|'boolean')` —
and the projection types the `items` schema from it, with any `each()` item constraints
composing on top. The element type **defaults to `string`** rather than staying untyped,
so a plain list attribute projects `items: {type: string}` and never manifests as
`unknown[]`; the far-more-common string list needs no declaration. The element type
narrows only the projected schema (it does not cast the serialized value), consistent
with the other projection-only field metadata (`description`, `example`).
