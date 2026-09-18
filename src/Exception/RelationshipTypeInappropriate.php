<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class RelationshipTypeInappropriate extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $relationshipName,
        public readonly string $currentRelationshipType,
        public readonly string $expectedRelationshipType,
    ) {
        parent::__construct(
            "The provided relationship '$relationshipName' is of type of $currentRelationshipType, but " .
            ($expectedRelationshipType !== '' ? "$expectedRelationshipType is" : 'it is not the one which is') . ' expected!',
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RELATIONSHIP_TYPE_INAPPROPRIATE',
            status: 400,
            title: 'Relationship type is inappropriate',
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer("/data/relationships/$this->relationshipName"),
            ),
        ];
    }
}
