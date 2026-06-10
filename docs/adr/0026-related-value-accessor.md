# Related-value resolution for the related / relationship endpoints

A data-layer adapter driving the `/{type}/{id}/{relationship}` (related) and
`/{type}/{id}/relationships/{relationship}` endpoints needs to resolve a
relationship from a parent resource and read its related domain value(s) —
without serializing — so it can hand those value(s) to the related type's
provider. We expose this as the smallest cohesive surface rather than a single
do-everything accessor: `AbstractResource::relationNamed(string $name):
?RelationInterface` resolves the declared (non-hidden) relation by its JSON:API
member name (or `null`), and the returned `RelationInterface` already answers
existence (non-null), cardinality (`isToMany()`) and the related type(s)
(`relatedTypes()`); we add `RelationInterface::readValue(mixed $model,
JsonApiRequestInterface $request): mixed` so the relation itself owns reading its
related value(s) off the parent (the linkage data, honouring a custom
column/extractor — the same path serialization reads from). This keeps the public
surface minimal and SRP-aligned (the resource resolves the field; the field reads
its value) and adds no new value object, leaving the existing protected
`relatedValue()` and the rest of the relation internals untouched.
