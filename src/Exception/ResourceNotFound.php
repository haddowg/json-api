<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

final class ResourceNotFound extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The requested resource is not found!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_NOT_FOUND',
            status: 404,
            title: 'Resource not found',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
            ),
        ];
    }
}
