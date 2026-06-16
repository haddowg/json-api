# A resource id can encode a distinct storage key, and shapes its route pattern

A resource's wire `id` is often a transform of the value the entity stores — a
binary UUID rendered as a string, an integer key hidden behind a reversible
codec. Core now lets the `Id` field carry an `IdEncoderInterface`
(`encode(mixed $storageKey): string` / `decode(string $wireId): mixed`, the
latter returning `null` when undecodable) attached with
`Id::encodeUsing()` and read via `Id::encoder()`. The entity always holds the
storage key: `serializeValue()` `encode()`s it on the way out (covering every
serialize path — the top-level id and any id-as-field, since both route through
`serializeWithoutRequest()`), and the create path `decode()`s a client-generated
id back to the storage key before setting it, so a created entity holds the same
storage key a read entity does and its rendered id round-trips. A well-formed but
undecodable id is a `422` `ResourceIdUndecodable` (the format constraint already
rejects a malformed id pre-hydration, so `decode()` runs on a well-formed value;
the `null` branch is the safety net). `PATCH` never sets the id, so it never
decodes.

The encode/decode boundary is split deliberately: **core owns the entity's own id
transform** (serialize-encode, create-decode), because that is the only place the
id flows as a typed value attached to the domain object. The **id-as-lookup-key
transforms** — decoding a route `{id}` before a database find, decoding linkage
ids in relationship writes — stay in the framework integration's data layer,
because they flow through the provider/persister SPI as wire strings; keeping that
SPI wire-id everywhere means the in-memory reference provider (which has no
encoder) needs no change. A type with no encoder behaves exactly as before: wire
== storage.

Separately, the `Id` field now carries a route `{id}` pattern, set by
`matchAs(string $pattern)` (the inner regex — Symfony anchors it) and read via
`routePattern()`. The format shortcuts `uuid()` / `ulid()` (new) / `numeric()` /
`pattern()` set it too, so one call governs both the create-id format constraint
and the URL requirement; a malformed id in a URL then `404`s at routing before any
handler runs. `pattern($regex)` strips a leading `^` / trailing `$` for the route
side while keeping the anchored ECMA-262 form for the constraint. The new
`ulid()` adds a `UlidFormat` constraint (a readonly VO mirroring `UuidFormat`,
compiled to a Crockford-base32 `pattern` since JSON Schema has no `ulid` format)
and the ULID route pattern, mirroring `uuid()`.
