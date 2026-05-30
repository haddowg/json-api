<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class ResourceNotFound extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('The requested resource is not found!', 404);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '404',
                code: 'RESOURCE_NOT_FOUND',
                title: 'Resource not found',
                detail: $this->getMessage(),
            ),
        ];
    }
}
