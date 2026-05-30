<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ClientGeneratedIdNotSupported extends AbstractJsonApiException
{
    public function __construct(public readonly string $clientGeneratedId)
    {
        parent::__construct(
            'Client generated ID ' . ($clientGeneratedId !== '' ? "'$clientGeneratedId' " : '') . 'is not supported!',
            403,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '403',
                code: 'CLIENT_GENERATED_ID_NOT_SUPPORTED',
                title: 'Client generated ID is not supported',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
