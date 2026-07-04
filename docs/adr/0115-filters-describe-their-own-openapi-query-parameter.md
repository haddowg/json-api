# Filters describe their own OpenAPI query parameter via `DescribesQueryParameter`

- **Status:** accepted

A filter with a **structured** wire shape now describes its own OpenAPI
`filter[<key>]` parameter — its OAS `style`/`explode` and the wrapping of its value
schema — by implementing `DescribesQueryParameter::describeQueryParameter(Schema): QueryParameterShape`.
The OpenAPI `OperationProjector` reduces over that method: it builds the value schema
from the filter's constraints, hands it to the filter (which wraps it — e.g. into a
`{min, max}` object — and pairs it with a style), and falls back to a plain scalar for
any filter that does not implement the interface. `Range`/`DateRange` implement it.

**Why.** The parameter shape was a closed `instanceof` switch on core's built-in
filter types (`instanceof Range` → `deepObject`) inside the projector, so a filter
with a non-scalar wire shape that core did not ship — a consumer's comma-list or
nested-object filter — could not be documented: it fell through to a scalar and its
real structure was invisible in the OpenAPI document (and to any generated client).
Moving the shape onto the filter makes it an extension point: a consumer-defined
filter now documents correctly with no core change. This is the parameter-envelope
analogue of [ADR 0114](0114-constraints-self-describe-their-json-schema.md) — where a
constraint self-describes its JSON Schema *value keyword*, a filter self-describes its
*parameter envelope*, one level up.

**Why on the filter (not in an adapter).** A parameter's `style`/`explode` and its
JSON Schema shape are framework-neutral OpenAPI facts, so — like a constraint's JSON
Schema — they belong on the filter value object, not in a per-framework adapter. Only
a filter's *execution* against a query is framework-specific and stays in the adapter's
filter handler / arm.

## Considered and rejected

- **A filter that builds its whole value schema.** Rejected: it would couple every
  filter to a `SchemaProjector`. Instead the projector projects the constraint-derived
  value schema and passes it in; the filter only **wraps** it, so a filter needs no
  projector and the value schema stays single-sourced with the field projection.
- **Leaving the closed switch.** Rejected: it is the whole point — a consumer's
  structured filter cannot document itself while the shape is hardcoded to `Range`.

## Consequences

`Range`/`DateRange` (namespace `Resource\Filter`) now depend on `OpenApi\Schema`,
`OpenApi\ParameterStyle` and the new `OpenApi\QueryParameterShape` — the same
`Resource → OpenApi` coupling ADR 0114 accepted, and for the same reason (these are
the neutral OpenAPI currency). Output is unchanged: `Range` still projects a
`deepObject` object parameter and `DateRange` still documents `date-time` bounds, so
the projector fixtures stay green; a new test proves a consumer-defined filter's
declared `form`/array shape reaches the document.
