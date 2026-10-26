<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceIdMissing extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('A resource ID must be included in the document!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_ID_MISSING',
            status: 400,
            title: 'Resource ID is missing',
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data',
            feature: ErrorFeature::Writes,
            description: 'The update request\'s resource object has no `id`. Include the id of the resource being updated as `data.id`.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'A resource ID must be included in the document!',
                source: ErrorSource::fromPointer('/data'),
            ),
        ];
    }
}
