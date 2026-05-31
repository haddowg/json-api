<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;

/**
 * Minimal {@see AbstractSerializer} double for a single resource type. Reads
 * `id`/`type` from an array domain value; a `kind` key (when present) overrides
 * the reported type, which lets a single instance stand in for a polymorphic
 * relationship's related object in tests.
 */
final class StubSerializer extends AbstractSerializer
{
    public function __construct(private readonly string $type) {}

    public function getType(mixed $object): string
    {
        if (\is_array($object) && isset($object['kind']) && \is_string($object['kind'])) {
            return $object['kind'];
        }

        return $this->type;
    }

    public function getId(mixed $object): string
    {
        if (\is_array($object) && isset($object['id']) && \is_scalar($object['id'])) {
            return (string) $object['id'];
        }

        return '0';
    }

    public function getMeta(mixed $object): array
    {
        return [];
    }

    public function getLinks(mixed $object): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object): array
    {
        return [];
    }
}
