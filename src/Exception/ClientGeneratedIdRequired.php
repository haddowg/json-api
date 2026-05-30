<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ClientGeneratedIdRequired extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('A client generated ID must be used!', 403);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '403',
                code: 'CLIENT_GENERATED_ID_REQUIRED',
                title: 'Required client generated ID',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
