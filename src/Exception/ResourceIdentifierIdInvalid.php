<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class ResourceIdentifierIdInvalid extends AbstractJsonApiException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("The resource ID must be a string instead of $type!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'RESOURCE_IDENTIFIER_ID_INVALID',
                title: 'Resource identifier ID is invalid',
                detail: "The resource ID must be a string instead of $this->type!",
            ),
        ];
    }
}
