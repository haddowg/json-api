# Relationship linkage is lazy by default, keyed per relation type

A relation renders links only (no `data`) until its value is loaded or included, so a forgotten opt-in can never cause one storage load per parent just to render identifiers. The default follows whether resolving linkage is free: `BelongsTo` and `MorphTo` (the key sits on the owning model) default eager, the to-many relations and `HasOne` default lazy, and `withData()` forces a lazy relation eager. "Is it loaded?" is answered by an adapter-supplied `RelationshipLoadStateInterface`; with none injected everything counts as loaded.

## Consequences

An included relationship always emits `data`, and a relationship that would render neither links nor meta always emits `data`, since a relationship object may not be empty.
