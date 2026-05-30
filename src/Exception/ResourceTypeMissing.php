<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceTypeMissing extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('A resource type must be included in the document!', 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'RESOURCE_TYPE_MISSING',
                title: 'Resource type is missing',
                detail: 'A resource type must be included in the document!',
                source: ErrorSource::fromPointer('/data'),
            ),
        ];
    }
}
