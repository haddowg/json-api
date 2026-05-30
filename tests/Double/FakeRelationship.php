<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * {@see AbstractRelationship} test double exposing protected state and a fixed
 * (always-present) data member.
 */
final class FakeRelationship extends AbstractRelationship
{
    /**
     * @param array<string, mixed> $defaultRelationships
     *
     * @return array<string, mixed>
     */
    protected function transformData(
        ResourceTransformation $transformation,
        ResourceTransformer $resourceTransformer,
        DataInterface $data,
        array $defaultRelationships,
    ): array {
        return [];
    }

    public function getRelationshipData(): mixed
    {
        return $this->getData();
    }

    public function isOmitDataWhenNotIncluded(): bool
    {
        return $this->omitDataWhenNotIncluded;
    }
}
