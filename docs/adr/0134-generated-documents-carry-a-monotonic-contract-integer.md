# Generated documents carry a monotonic contract integer, not a version or a feature list

> Amended: two rules this ADR originally left unstated are now written down. The contract is
> **release-scoped** (it moves at most once per release, not once per change), and an
> **absent `x-generator` means contract 1**. Without the first, the CI guard enforced a
> per-pull-request bump and ran a single unreleased cycle up to contract 5 for a document
> shape no consumer had seen more than once. The decision below is otherwise unchanged.

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

**The integer is release-scoped.** It names the structure a *release* emits, so it moves at
most once per release cycle however many commits that cycle takes: the first change to move
the structure moves the contract, and every later change in the same cycle leaves it where
that first one put it. A released document is the only document a generator has ever seen,
so a finer cadence counts commits nobody outside the repository can observe, and inflates
the integer for nothing. Two documents carrying the same contract have the same structure;
consecutive contracts differ by a release's worth of change.

**An absent `x-generator` means contract 1.** v1.0.0 shipped before the field existed, so
rather than leave the documents already in the world unreadable, contract 1 denotes the
shape v1.0.0 emitted and the release that added the stamp is contract 2. A generator
supporting `[1, 2]` handles both, and "no stamp" is a signal rather than a hole.

We rejected the package version as the signal. Semver describes the *library's* API, not
the document's structure: `1.4.2 → 1.4.3` may emit an identical document while a minor
release adds a member, so a generator keying on it would accept or reject for reasons
unrelated to what it reads. Being release-scoped does not make it the version — it makes
the release the unit of change, which is the granularity a consumer can actually observe.
We rejected a `features` token list too. It would name what changed, at the cost of two
hand-maintained lists — the server's and every generator's known-set — that must agree
forever; a drifting token list is worse than an integer that cannot drift. Nothing else
joins the object: the JSON:API version and the supported profiles/extensions already live
on the `JsonApi` component, and a generator name or package version would make every
release churn the document with nothing consuming them.

The integer is only worth carrying if it actually moves, so the bump is enforced rather
than remembered. A committed witness document (`ContractWitnessTest`) fails when the
projector's output diverges from it, and a CI guard compares that witness against **the one
the last release tag shipped** — not the pull request's base, which is what would demand a
bump per pull request. Structure moved and the contract is still at the tag's value: fail.
Structure moved and the contract is already ahead: pass, an earlier change in the cycle
bumped it. The guard cannot judge significance, so it is deliberately conservative: any
structural difference demands a bump, and only reworded prose is exempt. Over-bumping costs
one generator warning; under-bumping is the silent under-generation the field exists to
prevent.
