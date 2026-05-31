<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * A to-many relationship: its data member is a list of resource identifiers,
 * or an empty list when the relationship has no members.
 *
 * @see https://jsonapi.org/format/1.1/#document-resource-object-relationships
 */
class ToManyRelationship extends AbstractRelationship
{
    /**
     * @internal
     *
     * @param array<string, mixed> $defaultRelationships
     *
     * @return list<array<string, mixed>>|false
     */
    protected function transformData(
        ResourceTransformation $transformation,
        ResourceTransformer $resourceTransformer,
        DataInterface $data,
        array $defaultRelationships,
    ): array|false {
        if ($this->resource === null) {
            return false;
        }

        $object = $this->getData();
        if (\is_iterable($object) === false) {
            return [];
        }

        $result = [];
        foreach ($object as $item) {
            $resourceIdentifier = $this->transformResourceIdentifier($transformation, $resourceTransformer, $data, $item, $defaultRelationships);

            if ($resourceIdentifier !== null) {
                $result[] = $resourceIdentifier;
            }
        }

        return $result;
    }
}
