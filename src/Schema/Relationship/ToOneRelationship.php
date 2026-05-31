<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * A to-one relationship: its data member is a single resource identifier, or
 * `null` when the relationship is empty.
 *
 * @see https://jsonapi.org/format/1.1/#document-resource-object-relationships
 */
class ToOneRelationship extends AbstractRelationship
{
    /**
     * @internal
     *
     * @param array<string, mixed> $defaultRelationships
     *
     * @return array<string, mixed>|false|null
     */
    protected function transformData(
        ResourceTransformation $transformation,
        ResourceTransformer $resourceTransformer,
        DataInterface $data,
        array $defaultRelationships,
    ): array|false|null {
        if ($this->resource === null) {
            return false;
        }

        $object = $this->getData();
        if ($object === null) {
            return null;
        }

        return $this->transformResourceIdentifier($transformation, $resourceTransformer, $data, $object, $defaultRelationships);
    }
}
