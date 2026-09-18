<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceTypeMissing extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('A resource type must be included in the document!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_TYPE_MISSING',
            status: 400,
            title: 'Resource type is missing',
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'A resource type must be included in the document!',
                source: ErrorSource::fromPointer('/data'),
            ),
        ];
    }
}
