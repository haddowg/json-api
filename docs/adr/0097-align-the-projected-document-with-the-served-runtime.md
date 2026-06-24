# Align the projected OpenAPI document with the served runtime

An audit of the OpenAPI projection against the served runtime surfaced a set of
spec↔runtime mismatches — the document advertised behaviour the host does not
produce, or omitted behaviour it does. The projection is corrected so a client
generated against the document matches what the routes + handler actually do:

- **Relationship mutation never returns `204`.** The mutation arms always echo the
  linkage (`200`); the projector dropped the unreachable `204`.
- **Polymorphic to-one endpoints advertise no `filter[]`.** A `MorphTo` has no shared
  filter vocabulary, so the runtime `400`s any filter; the related and relationship
  to-one projections now suppress filter parameters for a polymorphic relation (a
  monomorphic to-one keeps them).
- **The create body honours the full client-id policy.** When the policy *forbids* a
  client id, the create resource schema now forbids `id` (the `false` schema, as the
  atomic `add` schema already does) rather than merely omitting it; when it *requires*
  one, `id` is present **and** `required`. This needs a new
  `TypeMetadataInterface::requiresClientId()` — the contract previously carried only
  `allowsClientId()`, collapsing the required vs optional distinction.
- **`?withCount` (the Countable profile) is projected** on the primary collection and
  the related / relationship to-many endpoints wherever the runtime honours it — a
  comma-list parameter whose enum is the valid tokens (`_self_` when the
  collection/relation is `countable()`, plus the countable relation names), profile-gated.
- **Auth/negotiation error statuses are accurate.** A secured operation advertises
  `401` (the unauthenticated twin of `403`); every write and relationship mutation
  advertises `406` (the `Accept` header is negotiated on every verb); a custom action
  advertises `406`, and `415` for a `None`/`Document` input mode (a `Raw` mode relaxes
  content-type).

A polymorphic to-one *with* an author-declared relation filter would previously
advertise a `filter[]` the runtime `400`s; that over-promise is now closed. The
`requiresClientId()` addition is the only contract change — every `TypeMetadataInterface`
implementer (the bundle's metadata source, the test fakes) supplies it.
