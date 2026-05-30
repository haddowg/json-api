<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceIdMissing extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('A resource ID must be included in the document!', 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'RESOURCE_ID_MISSING',
                title: 'Resource ID is missing',
                detail: 'A resource ID must be included in the document!',
                source: ErrorSource::fromPointer('/data'),
            ),
        ];
    }
}
