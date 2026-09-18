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
            context: ['relationship' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
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
