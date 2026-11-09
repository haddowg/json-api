# A related-only type renders no links to endpoints it doesn't serve

An adapter can serve a type only as other types' related or included resources, or as an action's request document, so its by-convention resource `self` and relationship `self`/`related` links would point at routes that don't exist. Overriding `emitsSelfLink()` only covers the resource `self` and needs a change to the author's class, so the server carries the set instead (`Server::withRelatedOnlyTypes()`), read off the resource's resolver like the read-only attribute rejection, and the type's OpenAPI metadata says so through the opt-in `DeclaresRelatedOnly`. Neither falls back to inferring the state from a missing `FetchOne`, because a type with no read routes already renders its links today and that would change existing documents and responses.

## Consequences

A related-only type's resource objects `$ref` their own `<Type>ResourceLinks` with no `self`, a new structure only those documents use, so it is a scoped generator contract tier and every other document keeps its stamp.
