# Multi-hop flattened (`on()`) related attributes, `computedUsing()`, and the eager-load declaration

A resource field gains an orthogonal trio: a **plain** attribute (read/write its
own column, cast), an attribute flattened **`on(path)`** from a chain of declared
to-one relations' related model (`'author'` or `'publisher.country'`), and a
**`computedUsing(closure)`** derived, read-only attribute. The two new declarations
are mutually exclusive (guarded with a `\LogicException`).

`computedUsing(\Closure)` is pure sugar over the existing primitives — `computed()`
(no backing column) + `extractUsing()` (the value closure, which owns the output,
no cast) + `readOnly()` on create **and** update — so a derived attribute is a
documented one-liner. The primitives stay public.

`on(string $path)` flattens a scalar attribute from a **chain of declared, to-one**
relations' related model — `$path` is a `.`-separated chain of relation names,
`'author'` (single hop) or `'publisher.country'` (multi-hop). The value is read
from / written onto the **final** related model in the chain. A segment MAY be
`hidden()` (the idiomatic internal association that never renders as a
relationship). On read, the owning resource walks the chain hop by hop — resolving
each segment hidden-inclusively against the owning type, then the prior segment's
related type's serializer (resolved through the injected `SerializerResolver`), each
hop honouring its relation's `column()`/`storedAs()` via `readValue()` — and reads
the field's own `column() ?? name()` off the final object with the normal
`serializeValue()` cast; **any intermediate null short-circuits the chain to a null
attribute**. On write, the value is `Accessor::set` onto the final related model
(mutated in place — Doctrine's UoW persists the dirty loaded entity on flush, the
in-memory store shares the reference; **no `DataPersister`/SPI change**); **any null
hop** is a **422** (`RelatedAttributeOwnerMissing`, require-exists, pointing at
`/data/attributes/<name>`) — a flattened attribute never auto-instantiates a related
model. The runtime walk enforces the same invariants as the boot validator as
defence-in-depth (an unknown or to-many segment, or an unresolvable related
serializer, is a `\LogicException`).

`on()` attributes hydrate **after** relationships (a `hydrateRelatedAttributes`
pass on both create and update paths), so a first-hop relation associated in the
same request body is visible when its flattened attribute is written. Plain
attributes still hydrate before relationships.

A core capability `DeclaresEagerLoadsInterface::eagerLoadRelationshipPaths()`
(instanceof-checked, mirroring `DeclaresFieldNamesInterface`) exposes the **dedup
set of every `on()` chain** (in field order). Core only **declares** the eager set;
it never renders it (load-not-render — a host executes the loading, walking each
chain segment by segment, and excludes it from `included`). Because every segment is
to-one, eager-loading never flips a relationship's linkage rendering, so the
document is unchanged. The eager set is author-declared and trusted, so a host MAY
bypass the client-include safeguards for it. (`with()` /
`alwaysLoadRelationships()` was considered and **dropped** — its only genuinely
unserved cases were niche/anti-pattern, and it dragged in a Relationship-Queries
overwrite + nested-validation + visible-to-many interaction that did not pay for
themselves; `on()` flattening and `?include` cover the real cases.)

The eager set is **validated fail-loud at boot / container warm-up** (so an author
mistake fails at `cache:clear` / deploy, not as a runtime 500). `EagerLoadValidator`
walks **every segment of every** `on()` chain across types — resolving each
segment's relation hidden-inclusively, then following its single related type to the
next serializer — and throws a developer-facing `\LogicException` on an **unknown
segment** (a typo that would silently no-op) or a **to-many segment**. `on()`
flattens a single scalar from a to-one chain, so a to-many at any depth (including an
**ancestor** in a dot-path, not just the leaf) is not flattenable; the message names
the type, path, and segment and points at `?include`. A segment may be `hidden()` or
visible — both pass, because the chain is to-one (eager-loading a to-one never flips
its linkage). A polymorphic / inventory-less segment whose next type cannot be
resolved to a single relation-declaring serializer is **left unwalked** (skipped,
not thrown), matching the host's include walk. The validator reads the
hidden-inclusive relation set off a serializer through a new
`DeclaresRelationsInterface::relationNamedIncludingHidden()` capability
(instanceof-checked; a bare serializer declares none, so a segment onto it is left
unresolved).

A flattened attribute projects in OpenAPI exactly like a normal attribute (its
hidden backing relation is not a relationship); a `computedUsing` attribute is
read-only in the schema. No projector change was needed.

**Breaking** only in the additive `FieldInterface::relatedVia()` and the new
`DeclaresEagerLoadsInterface` / `DeclaresRelationsInterface` on the serializer
surface; pre-1.0 this is a minor bump (`feat!`).
