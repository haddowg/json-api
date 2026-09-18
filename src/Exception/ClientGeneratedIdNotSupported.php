<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ClientGeneratedIdNotSupported extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $clientGeneratedId)
    {
        parent::__construct(
            'Client generated ID ' . ($clientGeneratedId !== '' ? "'$clientGeneratedId' " : '') . 'is not supported!',
            self::describe()->status,
        );
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'CLIENT_GENERATED_ID_NOT_SUPPORTED',
            status: 403,
            title: 'Client generated ID is not supported',
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
