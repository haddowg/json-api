# A dedicated parser for top-level relationship-endpoint linkage bodies

A relationship-endpoint body (`/{type}/{id}/relationships/{name}`) carries linkage
at the **top level** under `data` — `{"data": <linkage>}` — which is a different
shape from the whole-resource POST/PATCH body the existing
`getToOneRelationship()` / `getToManyRelationship()` read (those reach into
`data.relationships.{name}.data`). Reusing the whole-resource parsers here would
look in the wrong place, so `JsonApiRequest` gained
`getRelationshipDataToOne()` / `getRelationshipDataToMany()` that read the
top-level `data` member directly and produce the same `ToOneRelationship` /
`ToManyRelationship` value objects.

The two methods validate cardinality from the body's shape, throwing
`RelationshipTypeInappropriate` (400) on a mismatch: a list (including `[]`) sent
to the to-one parser, and a single object or `null` sent to the to-many parser. A
missing `data` member throws `RelationshipNotExists`. `data: null` (to-one) and
`data: []` (to-many) are the spec's clear signals and yield an empty relationship
rather than an error.
