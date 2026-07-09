# Client-selectable pagination: a server-composed strategy menu selected by `page[kind]`

Pagination was single-strategy per resource — `pagination()` returned one
`PaginatorInterface`, and the OpenAPI projector enumerated its `page[…]` keys through a
closed `match(PaginatorKind)`. We add a `MultiPaginator` (itself a `PaginatorInterface`,
so `pagination()`'s signature is unchanged) that composes several strategies
**server-side**; the client selects one per request with `page[kind]=<kind>`, where each
paginator declares its own free-form `kind()` (`page`/`offset`/`cursor`/`fixed`, or a
custom string via `->withKind()`) — the author declares the menu, a client cannot invent
a strategy. Selection is discriminator-first but not discriminator-only: a strategy-
**unique** key selects its strategy without `page[kind]` (`page[after]`/`[before]` →
cursor, `page[offset]`/`[limit]` → offset — honouring Ethan Resnick's cursor-pagination
profile, whose bare params must keep working), while a **shared** key
(`page[size]`/`[number]`) is ambiguous and requires `page[kind]`; an absent `page` falls
back to the author's declared default. The handler `resolve()`s the wrapper to its
concrete child **before** the `instanceof CursorPaginator` render/count branches, so a
wrapper that resolves to cursor is never mistaken for a count-based strategy.

Projection models `page` as a **single deepObject parameter** (serialising byte-identically
to the current `page[number]=…` wire form): a plain object schema for one strategy, a
`oneOf` of per-strategy branches — each with an optional `kind: {const}` plus
`additionalProperties: false` — for a menu, so the schema itself encodes the selection rule
(a unique-key object matches exactly one branch; a shared-key-only object matches several
and is invalid until `kind` disambiguates). Each `PaginatorInterface` now **self-describes**
its `page[…]` schema (`describePageSchema()`), retiring the coarse, closed `PaginatorKind`
enum and its `match` — a custom paginator projects its real keys, and the projector
collapses to one path. We also **lift** the batched-include cursor restriction (Laravel
ADR 0006 and its Doctrine twin): an included relation's cursor page is always a first page
(offset-0 under a keyset sort with an id tiebreak — the push-down already computes exactly
this, with `hasMore` via the N+1 probe), so the batcher mints the forward cursor per parent
from the boundary row rather than throwing.

We **decline** implicit key-inference as the *primary* selector (the first cursor page and
the shared `page[size]`/`[number]` keys cannot be disambiguated by keys alone — LaravelJsonApi's
`MultiPagination` avoids this only because its cursor/page key sets are disjoint, `limit`
vs `size`, whereas ours share `page[size]`), a closed-enum `kind` (it would force every
custom paginator to masquerade as a built-in), the literal-bracket-per-key projection (no
OpenAPI construct binds sibling query parameters, so mutual exclusion could only live in
prose), and deferring cursor-on-include (it is a first-page render gap, not a read
limitation, and lifting it removes the only caveat from the relations story). The
cursor-pagination profile is advertised per response off the rendered page's `profile()`,
so on a multi endpoint it appears only when cursor is the resolved strategy.
