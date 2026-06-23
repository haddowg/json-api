# Out-of-band resource-link contributor seam

A rendered resource object's `links` are built solely from the author's
`SerializerInterface::getLinks()` (plus the by-convention `self`). A framework
binding that owns URL generation (the router) often needs to publish extra
resource-level links the author cannot — a host-specific `describedby`, a
tenant-scoped alternate, a non-default action URL — and must be sure the author's
`getLinks()` (which may legitimately return `null`, or omit a key) cannot silently
suppress them.

`ResourceLinkContributorInterface` (one method, `linksFor(object, type, request):
array<string, Link>`) is the out-of-band seam, injected through
`SerializerResolverInterface` and threaded by `Server::withResourceLinkContributor()`,
mirroring the existing `RelationshipLinkageInterface` /
`RelationshipPaginationInterface` / `RelationshipCountInterface` /
`RelationshipLoadStateInterface` family exactly (same null-default, same per-resource
keying). Its contributed links are MERGED into the resource's `links` ALONGSIDE the
author's — with **author-wins precedence**: a key the author's `getLinks()` already
supplied is never overwritten, the convention `self` is still added when absent, and
a contributor returning `[]` adds nothing.

Unlike the relationship seams, the link object is built in `ResourceTransformer`,
which does NOT receive the resolver. So the contributor is reached off the rendered
resource: `SerializerResolverAwareInterface` gained a `serializerResolver()` getter
(implemented by `AbstractResource`), and the transformer reads
`resource->serializerResolver()?->resourceLinkContributor()`. A resource that is not
resolver-aware (a bare serializer that never opted in) therefore contributes nothing
— exactly as for relationships.

Core ships no implementation: with none injected (standalone library) a resource's
`links` are exactly what its serializer's `getLinks()` plus the convention `self`
produce, precisely as before this seam existed. **Breaking** only in the additive
`SerializerResolverInterface::resourceLinkContributor()` and
`SerializerResolverAwareInterface::serializerResolver()` methods (every in-tree
implementor gains the accessors); pre-1.0 a minor bump (`feat`). The companion bundle
change fills the seam from the router so a resource's host-owned links are published
without threading the router into every author's resource class.
