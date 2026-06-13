# A resource id is sourced by two explicit axes on the `Id` field

The id of a created resource was sourced implicitly: `AbstractResource::generateId()`
returned a v4 UUID that `hydrateId()` wrote onto the id column whenever the client
supplied none, so a non-UUID id silently became a UUID, and "let the database
assign the id" was only expressible through the obscure `Id::computed()` (null
column) trick. We replace that with two orthogonal, format-aware axes declared on
the `Id` field itself, and remove the `AbstractResource::acceptsClientGeneratedId()`
hook (core is pre-1.0) so the whole id policy reads from one place.

- **Client-id acceptance** (default: forbidden — a client `data.id` is a `403`
  `ClientGeneratedIdNotSupported`, today's behaviour). `allowClientId()` makes a
  client id optional (used if supplied, validated against the format);
  `requireClientId()` makes it mandatory, finally wiring the previously-unused
  `ClientGeneratedIdRequired` (`403`). Readers: `allowsClientId()`,
  `requiresClientId()`.
- **Server-side fallback when the client supplies none** (default: **store-provided**
  — core sets *nothing* and the store/DB assigns the id; this replaces the old
  auto-UUID). `generated()` mints from the declared self-generating format —
  `uuid()` reuses the v4 generator (now `Id::generateUuid()`), `ulid()` mints a
  Crockford-base32 ULID via a self-contained core generator (`Ulid::generate()`,
  no `symfony/uid` dependency since core is framework-agnostic). `generated()` on a
  non-self-generating format (`numeric()`/`pattern()`/none) is a config error — a
  `\LogicException` at declaration time. `generateUsing(\Closure $fn)` takes full
  control. The hydrator reads `generateIdValue(): ?string` — `null` means
  store-provided.

`hydrateId()` (create only — `PATCH` never sets the id) now: a client id is set
(decoded first only when an encoder is attached — the decode gate from ADR 0047 is
preserved, and a generated/closure value is a storage key set directly, never
decoded); otherwise the field's fallback applies — generate-and-set, or set nothing
for store-provided. The format helpers (`uuid()`/`ulid()`/`numeric()`/`pattern()`)
also declare the constraints a framework's semantic validator uses to validate a
**relationship linkage id** against the *related* type's id format — core only
declares; the bundle's validator executes.
