<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceTypeUnacceptable extends AbstractJsonApiException
{
    /**
     * @param list<string> $acceptedTypes
     */
    public function __construct(
        public readonly string $currentType,
        public readonly array $acceptedTypes,
    ) {
        parent::__construct(
            "Resource type '$currentType' is not a string or can't be accepted by the Hydrator!",
            409,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '409',
                code: 'RESOURCE_TYPE_UNACCEPTABLE',
                title: 'Resource type is unacceptable',
                detail: "Resource type '$this->currentType' is unacceptable!",
                source: ErrorSource::fromPointer('/data/type'),
            ),
        ];
    }
}
