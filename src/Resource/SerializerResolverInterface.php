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
     * The storage-aware predicate a relation consults — when it has opted in via
     * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::linkageOnlyWhenLoaded()}
     * — to decide whether its linkage is cheaply emittable, or `null` when no
     * adapter injected one (standalone core: every relation is treated as loaded
     * and linkage data is emitted as today).
     */
    public function relationshipLoadState(): ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
}
