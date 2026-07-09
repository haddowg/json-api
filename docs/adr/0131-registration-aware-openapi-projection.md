# The OpenAPI projection is registration-aware

The projector was **registration-blind**: it never consulted the server's registered
JSON:API profile set, so it advertised profile-gated affordances the runtime would not
honour. The `?withCount` parameter was emitted for any countable type, the `jsonapi`
object's `profile`/`ext` members were always an open `array<uri-string>`, the
Relationship Queries `relatedQuery` family was projected nowhere, and the cursor page
schema carried no profile marker. A profile the server did not register can never be
negotiated, so advertising its parameters documents a request that always `400`s.

We add `ServerMetadataInterface::profiles(): list<string>` (the registered profile URIs,
in registration order) and thread it through the projector, which now gates
profile-derived output on registration: `?withCount` is emitted only when the primary
type is countable **and** `CountableProfile` is registered; a `relatedQuery` deepObject
parameter (a single reusable `#/components/parameters/relatedQuery` component `$ref`d by
the collection/resource read endpoints of a relation-bearing type) is emitted only when
`RelationshipQueriesProfile` is registered; and the cursor page schema's `x-profile`
marker — emitted statically by the registration-blind `CursorPaginator` VO — is stripped
by the projector (top level and each `oneOf` branch) when `CursorPaginationProfile` is
not registered, though the cursor branch/parameter itself always stays (cursor pagination
functions without the profile). The `jsonapi` object's `profile` and `ext` members become
an `enum` of exactly the registered profile / advertised extension URIs when that set is
non-empty, and keep today's open `array<uri-string>` shape when it is empty (an empty
JSON Schema `enum` is invalid, so there is nothing to pin).

Registration decisions live **only** in the projector — the paginator value objects stay
pure and registration-blind, self-describing their page schema (and the cursor VO its
static marker) with the projector doing the one registration-aware strip. We derive the
advertised **extension** list from the existing `atomicOperations()` metadata (atomic is
the only extension core models: non-`null` ⟹ `[AtomicExtension::URI]`, else `[]`) rather
than adding a second `extensions()` interface accessor. Only the canonical `relatedQuery`
family is projected, not its byte-identical `rQ` shorthand alias: documenting both would
duplicate the parameter with no added meaning, and the two merge at parse time with the
canonical form winning. The generic `relatedQuery` component describes the
`relatedQuery[<path>][sort]` / `relatedQuery[<path>][filter][<key>]` shape once — it does
**not** enumerate a type's relations or a relationship's filter/sort keys, which the
addressed relationship's own vocabulary validates at runtime.
