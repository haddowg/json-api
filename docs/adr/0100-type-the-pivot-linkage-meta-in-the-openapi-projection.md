# Type the pivot linkage `meta.pivot` in the OpenAPI projection

A `belongsToMany` relation renders its declared pivot fields under each linkage
identifier's `meta.pivot` (the runtime decorates the linkage with the typed pivot
values), but the OpenAPI projection left the identifier's `meta` a permissive
`{type: object}` — so a client generated against the document saw no `pivot` shape
and could not type it. The projection now types `meta.pivot` from the relation's
pivot fields:

- `RelationMetadataInterface` gains `pivotFields(): list<FieldInterface>` — the
  declared pivot fields of a pivot-backed relation, empty for any other relation. It
  is the only contract addition; every implementer (the bundle's metadata adapter,
  the test fakes) supplies it.
- The projector composes the typed `meta.pivot` onto the **monomorphic** linkage
  identifier via `allOf: [{$ref: <RelatedType>ResourceIdentifier}, {properties: {meta:
  {properties: {pivot: {...}}}}}]` — the `$ref` carries `type`/`id`, the narrowing
  adds the typed `meta.pivot`. Each pivot field is projected by the same
  `SchemaProjector` the attributes use, so a pivot field and a same-typed attribute
  agree (and a backed-enum pivot field hoists into the shared enum component).

The typing lives on the linkage identifier, not on the shared
`<Type>ResourceIdentifier` component: pivot data is a fact of the *relationship*, not
of the related type (the same type linked without a pivot carries no `meta.pivot`).
It is therefore emitted everywhere that linkage appears — the embedded relationship
object **and** the relationship-document envelope — through the single
`linkageIdentifierSchema` path, so read responses and the relationship-mutation
request body (which share that envelope) both carry it. The `meta`/`pivot`/field
members are kept **optional** (no `required`): the read response always renders them
while a mutation request sends only the pivot fields it sets, and one shared schema
backs both. A polymorphic (`MorphToMany`) relation declares no pivot fields, so its
`anyOf` linkage is unchanged; the whole-resource create/update bodies keep their
permissive `relationships` placeholder (untyped today, out of scope here).
