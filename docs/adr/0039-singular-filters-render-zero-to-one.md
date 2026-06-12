# Singular filters render a zero-to-one response

A filter can declare itself **singular** — via the `singular()` builder on `Where`,
`WhereIn` and `WhereNotIn`, backed by a `Resource\Filter\SupportsSingular` capability
interface (`isSingular()`, opt-in like `HasDefaultValue`). When the client applies a
singular filter, the collection endpoint renders a **single resource object (or
`null`) in `data`** — the JSON:API zero-to-one shape — instead of an array. A filter
on a unique attribute (a slug, a UUID) is the canonical case.

This adopts the meaning "singular filter" already carries in the JSON:API ecosystem
(Laravel JSON:API), replacing the flag's earlier — unwired and never-shipped —
*anti-delimiter-splitting* meaning, whose docs collided with that well-known feature.
The flag stays metadata core only declares: the adapter's collection handler reads
`isSingular()` for an applied filter and collapses the response (the reference Symfony
bundle does so over both its providers). Core needed no rendering change —
`DataResponse::fromResource(null)` already renders `data: null`.

## Consequences

- The collapse applies only when the client sends the singular filter; without it the
  normal zero-to-many collection is returned. It has no effect on relationship endpoints.
- A mis-declared singular filter that matches more than one row renders the **first**
  match rather than erroring — the declaration asserts uniqueness; enforcing it is the
  data layer's job.
- A custom filter opts in by implementing `SupportsSingular`; the handler is generic
  over the interface, so the set of singular-capable filters can grow additively.
