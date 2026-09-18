<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceIdConflict extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $endpointId,
    ) {
        parent::__construct(
            "Resource id '$documentId' does not match the endpoint id '$endpointId'!",
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_ID_CONFLICT',
            status: 409,
            title: 'Resource id conflict',
            context: ['documentId' => ErrorContextType::Str, 'endpointId' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['documentId' => $this->documentId, 'endpointId' => $this->endpointId],
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
