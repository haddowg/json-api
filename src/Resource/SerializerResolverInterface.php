<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Resolves the {@see SerializerInterface} for a JSON:API resource type. A
 * relationship field uses it to serialize its related resource(s) without the
 * parent schema knowing the related schema directly. The {@see \haddowg\JsonApi\Server\Server}
 * (its schema registry) is the production implementation.
 */
interface SerializerResolverInterface
{
    /**
     * @throws \haddowg\JsonApi\Exception\JsonApiExceptionInterface when no serializer is registered for `$type`
     */
    public function serializerFor(string $type): SerializerInterface;

    /**
     * Whether a serializer is registered for `$type`.
     */
    public function hasSerializerFor(string $type): bool;

    /**
     * The storage-aware predicate a relation consults — when it is lazy
     * ({@see \haddowg\JsonApi\Resource\Field\RelationInterface::emitsDataOnlyWhenLoaded()})
     * — to decide whether its linkage is cheaply emittable, or `null` when no
     * adapter injected one (standalone core: every relation is treated as loaded
     * and linkage data is emitted as today).
     */
    public function relationshipLoadState(): ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;

    /**
     * The storage-aware resolver a countable relation consults — when it is
     * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::isCountable()} and
     * named in the request's `?withCount` — for the `meta.total` core renders on
     * the relationship object, or `null` when no adapter injected one (standalone
     * core: no count is available, so no `meta.total` is emitted).
     */
    public function relationshipCount(): ?\haddowg\JsonApi\Serializer\RelationshipCountInterface;

    /**
     * The storage-aware resolver a to-many relation consults — when the
     * Relationship Queries profile is negotiated — for the page-1 pagination state
     * core renders as the relationship-object `first` / `prev` / `next` (+ `last`)
     * links, or `null` when no adapter injected one (standalone core: no
     * relationship-object pagination links are emitted).
     */
    public function relationshipPagination(): ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface;

    /**
     * The storage-aware resolver a rendered to-many relation consults for its linkage
     * `data` supplied OUT-OF-BAND — the Relationship Queries profile's windowed page,
     * supplied per (parent, relation) so core renders it WITHOUT the host writing it
     * back onto (and corrupting) the parent's shared backing property. `null` when no
     * adapter injected one (standalone core: linkage is always read off the model, as
     * before this seam existed).
     */
    public function relationshipLinkage(): ?\haddowg\JsonApi\Serializer\RelationshipLinkageInterface;
}
