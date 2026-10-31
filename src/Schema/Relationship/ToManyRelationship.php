<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * A to-many relationship: its data member is a list of resource identifiers,
 * or an empty list when the relationship has no members.
 *
 * @see https://jsonapi.org/format/1.1/#document-resource-object-relationships
 */
final class ToManyRelationship extends AbstractRelationship
{
    /**
     * Renders `$members` in order as the linkage without reading their targets. Each
     * member's `target` runs only when the relationship is included, to supply the
     * object `included` renders with the member's `resource`; `$resource` is the
     * relationship's own serializer.
     *
     * @internal Set by {@see \haddowg\JsonApi\Resource\Field\MorphToMany} for a
     *           relation declared with `identifiedBy()`.
     *
     * @param list<array{type: string, id: string, resource: SerializerInterface, target: \Closure(): mixed}> $members
     *
     * @return $this
     */
    public function setStoredMembers(array $members, SerializerInterface $resource): static
    {
        $this->setData([], $resource);
        $this->storedMembers = $members;

        return $this;
    }

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

        if ($this->storedMembers !== null) {
            $result = [];
            foreach ($this->storedMembers as $member) {
                $this->includeStoredTarget($transformation, $resourceTransformer, $data, $member['type'], $member['resource'], $member['target'], $defaultRelationships);
                $result[] = ['type' => $member['type'], 'id' => $member['id']];
            }

            return $result;
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
