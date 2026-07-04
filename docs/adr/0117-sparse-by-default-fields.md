# Fields can be sparse by default (`sparseByDefault()`)

- **Status:** accepted

A field marked `sparseByDefault()` is **omitted from the default response** and
rendered only when the client explicitly names it in a `fields[type]` member — the
opt-in inverse of the usual sparse-fieldset rule (a field is present unless a fieldset
excludes it). It applies symmetrically to attributes and relations, and the omission
happens in `AbstractResource::getAttributes()`/`getRelationships()` before the value
hook is added, so the (potentially expensive) serialization never runs on a request
that did not ask for the field.

**Why.** The field model had `hidden()` (never rendered) and `writeOnly()` (accepted
but never rendered) but no "present, but opt-in per request" tier — the natural home
for an expensive computed/derived attribute a client seldom needs (a full-text score,
an aggregate, a rendered blob). This fills the gap without a new request concept: the
decision reuses `JsonApiRequest::getIncludedFields($type)` (the names explicitly
requested for a type), so a sparse field renders exactly when it appears there. It
stays a fully declared member — a valid `fields[type]` name and a documented schema
member — so nothing else about validation or the generated schema changes.

## Consequences

`sparseByDefault()` is orthogonal to `hidden()`/`writeOnly()`: a write-only or
unconditionally-hidden field is never rendered even when named, so it wins. Distinct
from the pre-existing `notSparseField()` (a field that opts *out* of fieldset
filtering and is therefore *always* rendered) — the two are opposite ends of the
default-visibility spectrum, with the normal field in the middle. The generated
OpenAPI does not yet flag a sparse-by-default member as opt-in; that is a
documentation-only follow-up.
