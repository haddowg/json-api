<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class ApplicationError extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Application exception is thrown!', 500);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '500',
                code: 'APPLICATION_ERROR',
                title: 'Application error',
                detail: 'An application error has occurred!',
            ),
        ];
    }
}
