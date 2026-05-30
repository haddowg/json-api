<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator\Relationship;

use haddowg\JsonApi\Schema\ResourceIdentifier;

/**
 * Represents a to-many relationship in a hydrator context.
 *
 * When the request carries `"data": []`, the relationship is "clearing"
 * ({@see isEmpty()} === true). Otherwise wraps the list of resource identifiers.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#document-resource-object-relationships
 */
final readonly class ToManyRelationship
{
    /**
     * @param list<ResourceIdentifier> $resourceIdentifiers
     */
    public function __construct(public array $resourceIdentifiers = []) {}

    /**
     * @return list<string>
     */
    public function getResourceIdentifierTypes(): array
    {
        return \array_map(static fn(ResourceIdentifier $ri): string => $ri->type, $this->resourceIdentifiers);
    }

    /**
     * @return list<?string>
     */
    public function getResourceIdentifierIds(): array
    {
        return \array_map(static fn(ResourceIdentifier $ri): ?string => $ri->id, $this->resourceIdentifiers);
    }

    /**
     * @return list<?string>
     */
    public function getResourceIdentifierLids(): array
    {
        return \array_map(static fn(ResourceIdentifier $ri): ?string => $ri->lid, $this->resourceIdentifiers);
    }

    /**
     * Whether the request wants to clear the relationship (sent an empty array as data).
     */
    public function isEmpty(): bool
    {
        return $this->resourceIdentifiers === [];
    }
}
