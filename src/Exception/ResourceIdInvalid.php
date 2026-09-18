<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceIdInvalid extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("The resource ID must be a string instead of $type!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_ID_INVALID',
            status: 400,
            title: 'Resource ID is invalid',
            context: ['type' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "The resource ID must be a string instead of $this->type!",
                context: ['type' => $this->type],
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
