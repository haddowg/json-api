<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\RendersRelationsTrait;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so a decorator that rebuilds a relation and re-renders a
 * response through itself type-checks the way an adapter writes one.
 */
final class RebindingSerializerTypes extends AbstractSerializer
{
    use RendersRelationsTrait;

    /**
     * @param array<string, RelationInterface> $relations
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly SerializerResolverInterface $resolver,
        private readonly array $relations,
    ) {}

    public static function rebind(DataResponse $response, SerializerResolverInterface $resolver, RelationInterface $relation): DataResponse
    {
        return $response->withSerializer(new self($response->serializer(), $resolver, [$relation->name() => $relation]));
    }

    public static function rebindRelated(RelatedResponse $response, SerializerResolverInterface $resolver, RelationInterface $relation): RelatedResponse
    {
        return $response->withSerializer(new self($response->serializer(), $resolver, [$relation->name() => $relation]));
    }

    public static function rebindAccepted(AcceptedResponse $response, SerializerResolverInterface $resolver, RelationInterface $relation): AcceptedResponse
    {
        $serializer = $response->serializer();

        return $serializer === null ? $response : $response->withSerializer(new self($serializer, $resolver, [$relation->name() => $relation]));
    }

    public static function rebindLinkage(IdentifierResponse $response, SerializerResolverInterface $resolver, RelationInterface $relation): IdentifierResponse
    {
        return $response->withSerializer(new self($response->serializer(), $resolver, [$relation->name() => $relation]));
    }

    public function getType(mixed $object): string
    {
        return $this->inner->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getMeta($object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner->getLinks($object, $request);
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $relationships = $this->inner->getRelationships($object, $request);
        foreach ($this->relations as $name => $relation) {
            $relationships[$name] = self::relationshipCallable($relation, $this->resolver);
        }

        return $relationships;
    }
}
