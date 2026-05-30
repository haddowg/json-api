<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class RelationshipTypeInappropriate extends AbstractJsonApiException
{
    public function __construct(
        public readonly string $relationshipName,
        public readonly string $currentRelationshipType,
        public readonly string $expectedRelationshipType,
    ) {
        parent::__construct(
            "The provided relationship '$relationshipName' is of type of $currentRelationshipType, but " .
            ($expectedRelationshipType !== '' ? "$expectedRelationshipType is" : 'it is not the one which is') . ' expected!',
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'RELATIONSHIP_TYPE_INAPPROPRIATE',
                title: 'Relationship type is inappropriate',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer("/data/relationships/$this->relationshipName"),
            ),
        ];
    }
}
