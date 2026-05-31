<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;

/**
 * Configurable {@see AbstractSerializer} test double.
 */
final class StubResource extends AbstractSerializer
{
    /**
     * @param array<string, mixed>    $meta
     * @param array<string, callable> $attributes
     * @param list<string>            $defaultRelationships
     * @param array<string, callable> $relationships
     */
    public function __construct(
        private readonly string $type = '',
        private readonly string $id = '',
        private readonly array $meta = [],
        private readonly ?ResourceLinks $links = null,
        private readonly array $attributes = [],
        private readonly array $defaultRelationships = [],
        private readonly array $relationships = [],
    ) {}

    public function getType(mixed $object): string
    {
        return $this->type;
    }

    public function getId(mixed $object): string
    {
        return $this->id;
    }

    public function getMeta(mixed $object): array
    {
        return $this->meta;
    }

    public function getLinks(mixed $object): ?ResourceLinks
    {
        return $this->links;
    }

    public function getAttributes(mixed $object): array
    {
        return $this->attributes;
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->defaultRelationships;
    }

    public function getRelationships(mixed $object): array
    {
        return $this->relationships;
    }

    public function getRequest(): ?JsonApiRequestInterface
    {
        return $this->request;
    }

    public function getObject(): mixed
    {
        return $this->object;
    }
}
