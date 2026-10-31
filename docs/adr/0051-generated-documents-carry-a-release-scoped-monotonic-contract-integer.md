# Generated documents carry a release-scoped, monotonic contract integer

Every projected document stamps `info.x-generator: {contract: N}`, a hand-bumped integer that a code generator compares against the `[min, max]` it supports, erroring below and warning above. Its job is the silent failure: a generator older than the server would otherwise emit a client missing capabilities with nothing indicating the gap. The integer is release-scoped (it moves at most once per release, since a released document is all a consumer ever sees), and an absent stamp reads as the first contract.

## Considered options

- The package version: semver describes the library's API, not the document's structure.
- A feature-token list: two hand-maintained lists (server and every generator) that must agree forever.

## Consequences

The bump is enforced: `ContractWitnessTest` fails when output diverges from a committed witness, and a CI guard compares that witness with the one the last release tag shipped, demanding a bump for any structural change. Prose (`description`, `summary`) and example values are stripped before comparing because generators never key on them, though whether a media type carries an example at all is structure. A bump may be scoped to a structure only some documents use: `CONTRACT` is the highest stamp, a document using no scoped structure keeps `BASELINE` byte-identical, and a second witness without the scoped structures forces `BASELINE` up when a change moves every document.
