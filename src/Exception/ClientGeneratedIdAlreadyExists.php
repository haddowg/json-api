<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ClientGeneratedIdAlreadyExists extends AbstractJsonApiException
{
    public function __construct(public readonly string $clientGeneratedId)
    {
        parent::__construct("Client generated ID '$clientGeneratedId' already exists!", 409);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '409',
                code: 'CLIENT_GENERATED_ID_ALREADY_EXISTS',
                title: 'Client generated ID already exists',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
