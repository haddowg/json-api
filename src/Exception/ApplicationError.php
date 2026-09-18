<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class ApplicationError extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('Application exception is thrown!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'APPLICATION_ERROR',
            status: 500,
            title: 'Application error',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'An application error has occurred!',
            ),
        ];
    }
}
