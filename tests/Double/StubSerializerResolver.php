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
final class StubSerializerResolver implements SerializerResolver
{
    /**
     * @var array<string, SerializerInterface>
     */
    private array $serializers = [];

    public function __construct(string ...$types)
    {
        if ($types === []) {
            $types = ['users', 'comments', 'profiles', 'tags', 'posts', 'videos'];
        }

        foreach ($types as $type) {
            $this->serializers[$type] = new StubSerializer($type);
        }
    }

    public function serializerFor(string $type): SerializerInterface
    {
        return $this->serializers[$type] ?? throw new ResourceNotFound();
    }

    public function hasSerializerFor(string $type): bool
    {
        return isset($this->serializers[$type]);
    }
}
