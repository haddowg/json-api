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
            exampleDetail: 'An application error has occurred!',
            description: 'The server failed to handle the request because of a fault on its side, not because of anything in the request. Retrying won\'t help; report it to the API\'s maintainers.',
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
