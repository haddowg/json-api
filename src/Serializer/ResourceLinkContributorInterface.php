<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\Link;

/**
 * Out-of-band contributor of extra `links` members onto a rendered resource object
 * — so an integration (the framework binding) can inject resource-level links the
 * author's {@see SerializerInterface::getLinks()} override knows nothing about, and
 * which that override therefore cannot accidentally drop.
 *
 * Its motivating consumer is a framework binding that owns URL generation (the
 * router): the host knows the route names + base URI that produce a resource's
 * `self`-adjacent links (a host-specific `describedby`, a tenant-scoped alternate,
 * a non-default action URL), but the author's resource declaration does not. The
 * author returns its own hand-written `links` from `getLinks()`; this seam MERGES
 * the host's contribution alongside them, WITHOUT the host having to thread its
 * router into every author's resource class — and without the author's override
 * being the only writer of the `links` object (a hand-written `getLinks()` that
 * returns `null`, or a container omitting a key, can no longer silently suppress a
 * link the host must publish).
 *
 * PRECEDENCE is author-wins: an author `getLinks()`-supplied key is NEVER
 * overwritten by a contributor key of the same name (the author's deliberate value
 * stands), and the by-convention `self` link is still added when neither the author
 * nor a contributor supplied one. A contributor returning `[]` adds nothing.
 *
 * Reached through the resolver-aware resource: {@see \haddowg\JsonApi\Transformer\ResourceTransformer}
 * does not itself receive the resolver, so it reads this contributor off the
 * rendered resource's {@see \haddowg\JsonApi\Resource\SerializerResolverAwareInterface::serializerResolver()}.
 * A resource that is not resolver-aware (a bare serializer that never opted in)
 * therefore receives no contribution — exactly as for relationships.
 *
 * Injected through the {@see \haddowg\JsonApi\Resource\SerializerResolverInterface},
 * mirroring {@see RelationshipLinkageInterface} / {@see RelationshipPaginationInterface}
 * / {@see RelationshipCountInterface} / {@see RelationshipLoadStateInterface}. Core
 * ships no implementation: with none injected (standalone library) a resource's
 * `links` are exactly what {@see SerializerInterface::getLinks()} (plus the
 * convention `self`) produce, precisely as before this seam existed.
 */
interface ResourceLinkContributorInterface
{
    /**
     * Returns the extra named links to merge into the resource object's `links`
     * for `$object` of resource type `$type`, or `[]` to contribute nothing — in
     * which case the resource's `links` are exactly what the author's
     * {@see SerializerInterface::getLinks()} (plus the convention `self`) produce.
     *
     * A returned key that the author's `getLinks()` already supplied is IGNORED
     * (author-wins precedence): this seam only ADDS links the author did not
     * declare, it never overrides one the author deliberately set.
     *
     * @return array<string, Link> named links keyed by their `links` member name
     */
    public function linksFor(mixed $object, string $type, JsonApiRequestInterface $request): array;
}
