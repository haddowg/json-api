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
            exampleDetail: 'A resource type must be included in the document!',
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data',
            feature: ErrorFeature::Writes,
            description: 'The resource object in `data` has no `type`. Add the type of the resource the endpoint serves.',
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
