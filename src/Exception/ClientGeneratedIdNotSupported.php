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
            exampleDetail: 'Client generated ID \'1\' is not supported!',
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/id',
            feature: ErrorFeature::Writes,
            description: 'The create request supplied an `id`, but this resource type assigns its own ids. Leave `data.id` out and the server generates one.',
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
