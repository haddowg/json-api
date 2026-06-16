<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Resource\SerializerResolver;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A {@see SerializerResolver} double backed by a fixed set of
 * {@see StubSerializer}s, one per registered type.
 */
final class StubSerializerResolver implements \haddowg\JsonApi\Resource\SerializerResolverInterface
{
    /**
     * @var array<string, SerializerInterface>
     */
    private array $serializers = [];

    private ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface $relationshipLoadState = null;

    private ?\haddowg\JsonApi\Serializer\RelationshipCountInterface $relationshipCount = null;

    private ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface $relationshipPagination = null;

    public function __construct(string ...$types)
    {
        if ($types === []) {
            $types = ['users', 'comments', 'profiles', 'tags', 'posts', 'videos'];
        }

        foreach ($types as $type) {
            $this->serializers[$type] = new StubSerializer($type);
        }
    }

    public function withRelationshipLoadState(?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface $relationshipLoadState): self
    {
        $this->relationshipLoadState = $relationshipLoadState;

        return $this;
    }

    public function serializerFor(string $type): SerializerInterface
    {
        return $this->serializers[$type] ?? throw new ResourceNotFound();
    }

    public function hasSerializerFor(string $type): bool
    {
        return isset($this->serializers[$type]);
    }

    public function relationshipLoadState(): ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface
    {
        return $this->relationshipLoadState;
    }

    public function withRelationshipCount(?\haddowg\JsonApi\Serializer\RelationshipCountInterface $relationshipCount): self
    {
        $this->relationshipCount = $relationshipCount;

        return $this;
    }

    public function relationshipCount(): ?\haddowg\JsonApi\Serializer\RelationshipCountInterface
    {
        return $this->relationshipCount;
    }

    public function withRelationshipPagination(?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface $relationshipPagination): self
    {
        $this->relationshipPagination = $relationshipPagination;

        return $this;
    }

    public function relationshipPagination(): ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface
    {
        return $this->relationshipPagination;
    }
}
