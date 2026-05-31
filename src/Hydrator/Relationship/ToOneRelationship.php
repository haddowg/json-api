<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator\Relationship;

use haddowg\JsonApi\Schema\ResourceIdentifier;

/**
 * Represents a to-one relationship in a hydrator context.
 *
 * When the request carries `"data": null`, the relationship is "clearing"
 * ({@see isEmpty()} === true). When a resource identifier is present it wraps it.
 *
 * @see https://jsonapi.org/format/1.1/#document-resource-object-relationships
 */
final readonly class ToOneRelationship
{
    public function __construct(public ?ResourceIdentifier $resourceIdentifier = null) {}

    /**
     * Whether the request wants to clear the relationship (sent `null` as data).
     */
    public function isEmpty(): bool
    {
        return $this->resourceIdentifier === null;
    }
}
