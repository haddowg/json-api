# An empty to-one binds its serializer so it can emit `data: null`

When a non-deferred to-one relationship reads a `null` related value,
`AbstractRelation::buildToOne()` now still binds the related type's serializer
onto the relationship object (previously it skipped `setData()` entirely for a
null value, leaving the relationship's `resource` unset). Without the serializer
bound, `ToOneRelationship::transformData()` short-circuits to `false` and the
`data` member is omitted — fine inside a full resource document, but wrong for the
relationship-linkage endpoint (`GET /{type}/{id}/relationships/{name}`), where the
spec requires the document's primary `data` to be `null` for an empty to-one.
Binding the serializer lets the transformer render `data: null`; the full-document
path is unaffected because its include/current-relationship gate already governs
whether the data member appears. The same `setData`-always change is mirrored in
`buildToMany()` so a null/absent to-many renders `data: []` on its linkage
endpoint rather than omitting the member.
