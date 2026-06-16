# A resource's URI path segment can differ from its JSON:API type

The JSON:API `type` member is the resource's identity in the document; it need
not equal the path segment under which the resource is routed. Applications
routinely want a URL-friendly segment that differs — a plural (`/books` for type
`book`) or a kebab-cased name (`/blog-posts` for type `blogPost`). Core now lets a
resource declare that segment: `AbstractResource::$uriType` (a static, mirroring
`$type`, defaulting to `''` = "use `$type`") read by `AbstractResource::uriType()`,
and the by-convention relationship `self`/`related` links use it in the path
position instead of `getType()`. The `type` member, operation/Target resolution,
and serializer lookup are unchanged — only the generated link *path* changes.

The capability is exposed as an optional `Serializer\UriTypeAwareInterface`
(`uriType(): string`) rather than a new method on `SerializerInterface`. This
keeps it **backward compatible** (existing and external serializers, and bare
serializer/hydrator pairs, are unaffected — the link builder falls back to
`getType()` for a serializer that is not URI-type-aware) and **escape-hatch
friendly** (a custom serializer opts in only if it wants a custom segment). It
also respects the layer boundary: the link builder lives in `Schema`, which may
depend on `Serializer` but not on `Resource`, so it checks the interface rather
than `instanceof AbstractResource`.

The relationship convention links are the only place core puts a resource type
into a generated path (top-level document and resource `self` links are
host-supplied, not auto-generated), so that is the only site changed.
