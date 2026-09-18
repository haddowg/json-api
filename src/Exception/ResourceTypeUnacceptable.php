<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ResourceTypeUnacceptable extends AbstractJsonApiException implements DescribedErrorInterface
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
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_TYPE_UNACCEPTABLE',
            status: 409,
            title: 'Resource type is unacceptable',
            context: ['type' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Resource type '$this->currentType' is unacceptable!",
                context: ['type' => $this->currentType],
                source: ErrorSource::fromPointer('/data/type'),
            ),
        ];
    }
}
