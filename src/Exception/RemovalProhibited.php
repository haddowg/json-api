<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class RemovalProhibited extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $relationshipName)
    {
        parent::__construct("Removal of relationship '$relationshipName' is prohibited!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'REMOVAL_PROHIBITED',
            status: 403,
            title: 'Removal is prohibited',
            exampleDetail: 'Removal of relationship \'tags\' is prohibited!',
            context: ['relationship' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/relationships/tags',
            feature: ErrorFeature::Writes,
            description: 'This relationship does not let a client remove members from it or clear it. Don\'t retry the request; the relationship changes only in the ways the API documents for it.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['relationship' => $this->relationshipName],
                source: ErrorSource::fromPointer("/data/relationships/$this->relationshipName"),
            ),
        ];
    }
}
