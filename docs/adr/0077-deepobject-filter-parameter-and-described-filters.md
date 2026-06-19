# DeepObject filter parameters and described filters

The OpenAPI projector now documents a structured `Range`/`DateRange` filter as an
OAS 3.1 **`deepObject`** parameter (`style: deepObject, explode: true`) — the
standard way to describe a nested-object query parameter — so a generated client
knows to serialize the filter as `filter[<key>][min]=…&filter[<key>][max]=…` rather
than a single scalar. The `Parameter` value object gained optional `style`
(a new `ParameterStyle` enum) and `explode` members, both serialized only when set,
so every existing scalar parameter is byte-for-byte unchanged.

Each `filter[<key>]` parameter now carries the **filter's own declared description**
(the convenience filters preset one — "Matches values containing the given
substring.", "Matches values within the given inclusive numeric range…") instead of
a generic per-key label, closing the Slice-1 gap where those descriptions were
declared but inert in the document. The projector reads the description through a new
`DescribedFilter` interface (which `HasValueConstraints` already satisfies via its
`getDescription()`), so the access is type-safe over the bare `FilterInterface`
rather than reaching into the trait or branching on concrete classes.

`DateRange::make()` now presets an ISO-8601 `Pattern` (reusing the existing `pattern()`
builder, not a new constraint type) so a framework validator rejects a malformed
bound — `filter[<key>][min]=banana` — as a clean `400` before the filter reaches the
data layer, the temporal twin of `Range`'s preset `numeric()`.
