<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;

/**
 * A {@see SerializerInterface} decorator that renders a heterogeneous (mixed-type)
 * collection by resolving each object's real serializer — typically via
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()} —
 * and delegating every method to it. Binding one of these to a relationship gives
 * each member its own correct `type` / `id` / attributes, so a polymorphic to-many
 * relationship renders per-member without any change to the transformer,
 * {@see \haddowg\JsonApi\Schema\Relationship\ToManyRelationship}, or a host's
 * related-resource collection.
 */
final class PolymorphicSerializer implements SerializerInterface
{
    /**
     * @param \Closure(mixed): SerializerInterface $serializerFor resolves the
     *                                                            serializer for a given member object
     */
    public function __construct(private readonly \Closure $serializerFor) {}

    public function getType(mixed $object): string
    {
        return $this->for($object)->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->for($object)->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->for($object)->getMeta($object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->for($object)->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->for($object)->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->for($object)->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->for($object)->getRelationships($object, $request);
    }

    private function for(mixed $object): SerializerInterface
    {
        return ($this->serializerFor)($object);
    }
}
