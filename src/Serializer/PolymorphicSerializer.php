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
final class PolymorphicSerializer implements SerializerInterface, IncludeControlsInterface
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

    public function getNonIncludableRelationships(mixed $object): array
    {
        $serializer = $this->for($object);

        return $serializer instanceof IncludeControlsInterface
            ? $serializer->getNonIncludableRelationships($object)
            : [];
    }

    /**
     * The root-scoped depth override and allowed-include-paths whitelist resolve
     * against the request's PRIMARY/root serializer, never a relationship-member
     * one — a polymorphic serializer only ever serializes related members, where
     * there is no single inner serializer to delegate to (members span types). It
     * is therefore unrestricted on both: a member's own resource still enforces
     * its per-relation includability during the recursion.
     */
    public function maxIncludeDepth(): ?int
    {
        return null;
    }

    public function getAllowedIncludePaths(): ?array
    {
        return null;
    }

    private function for(mixed $object): SerializerInterface
    {
        return ($this->serializerFor)($object);
    }
}
