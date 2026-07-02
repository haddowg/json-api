# OpenAPI write components are gated on the operation allow-list

The OpenAPI projection now emits a type's **write** component schemas only when the
type's per-type operation allow-list (`TypeMetadataInterface::operations()`) exposes
the corresponding write:

- `<Type>CreateAttributes` / `<Type>CreateRequest` and `<Type>AtomicAdd` — only when
  `Create` is allowed;
- `<Type>UpdateAttributes` / `<Type>UpdateRequest` and `<Type>AtomicUpdate` — only when
  `Update` is allowed;
- the Atomic Operations `data` union (`AtomicOperation.data.anyOf`) references a type's
  `AtomicAdd`/`AtomicUpdate` only where those shapes were emitted.

Previously these were gated on `hasFields()` alone, so a read-only type (one whose
allow-list exposes only `FetchOne`/`FetchCollection`) still emitted `CreateRequest`,
`UpdateRequest`, `AtomicAdd`, `AtomicUpdate` and their attributes — components no path
references, describing writes the server rejects. That over-advertised the write
surface and, for the atomic union, pointed at add/update shapes the runtime would 4xx.

The gate is the same `operations()` allow-list the **path** projection already uses to
decide which CRUD paths to emit, so component emission now tracks path emission exactly:
a read-only type emits its read components (`Attributes`, `Resource`, documents) and no
write components, and the atomic union offers write shapes only for types that accept
that write. Read components are unaffected. Taken before the 1.0 freeze.
