# Generated documents carry a monotonic contract integer, not a version or a feature list

A code generator consuming a projected OpenAPI document has to know when it is too old for
the server that produced it. Two failure directions exist and only one is dangerous. A
server **older** than the generator expects already fails loudly: a required structure is
absent and a typed reader says so. A server **newer** fails silently — the generator does
not read the new structure, emits a client missing capabilities the server offers, and
nothing anywhere indicates a gap.

So every document now stamps `info.x-generator: {"contract": N}`, a single monotonic
integer bumped by hand whenever the emitted structure moves. A generator declares the
`[min, max]` it supports: below `min` it errors, above `max` it warns that newer
capabilities went ungenerated.

We rejected the package version as the signal. Semver describes the *library's* API, not
the document's structure: `1.4.2 → 1.4.3` may emit an identical document while a minor
release adds a member, so a generator keying on it would accept or reject for reasons
unrelated to what it reads. We rejected a `features` token list too. It would name what
changed, at the cost of two hand-maintained lists — the server's and every generator's
known-set — that must agree forever; a drifting token list is worse than an integer that
cannot drift. Nothing else joins the object: the JSON:API version and the supported
profiles/extensions already live on the `JsonApi` component, and a generator name or
package version would make every release churn the document with nothing consuming them.

The integer is only worth carrying if it actually moves, so the bump is enforced rather
than remembered. A committed witness document (`ContractWitnessTest`) fails when the
projector's output diverges from it, and a CI guard fails a pull request whose witness
structure changed without the contract changing. The guard cannot judge significance, so
it is deliberately conservative: any structural difference demands a bump, and only
reworded prose is exempt. Over-bumping costs one generator warning; under-bumping is the
silent under-generation the field exists to prevent.
