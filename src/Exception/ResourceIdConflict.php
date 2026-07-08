<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceIdConflict extends AbstractJsonApiException
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $endpointId,
    ) {
        parent::__construct(
            "Resource id '$documentId' does not match the endpoint id '$endpointId'!",
            409,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '409',
                code: 'RESOURCE_ID_CONFLICT',
                title: 'Resource id conflict',
                detail: $this->getMessage(),
                context: ['documentId' => $this->documentId, 'endpointId' => $this->endpointId],
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
