<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class AdditionProhibited extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $relationshipName)
    {
        parent::__construct("Addition to relationship '$relationshipName' is prohibited!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ADDITION_PROHIBITED',
            status: 403,
            title: 'Addition is prohibited',
            exampleDetail: 'Addition to relationship \'tags\' is prohibited!',
            context: ['relationship' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/relationships/tags',
            feature: ErrorFeature::Writes,
            description: 'This relationship does not let a client add members to it. Don\'t retry the request; the relationship changes only in the ways the API documents for it.',
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
