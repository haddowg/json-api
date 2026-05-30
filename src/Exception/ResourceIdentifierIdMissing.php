<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class ResourceIdentifierIdMissing extends AbstractJsonApiException
{
    /**
     * @param array<string, mixed> $resourceIdentifier
     */
    public function __construct(public readonly array $resourceIdentifier)
    {
        parent::__construct('An ID or local ID (lid) for the resource identifier must be included!', 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'RESOURCE_IDENTIFIER_ID_MISSING',
                title: 'An ID for the resource identifier is missing',
                detail: 'An ID or local ID (lid) for the resource identifier must be included!',
            ),
        ];
    }
}
