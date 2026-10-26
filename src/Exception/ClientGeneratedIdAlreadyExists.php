<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ClientGeneratedIdAlreadyExists extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $clientGeneratedId)
    {
        parent::__construct("Client generated ID '$clientGeneratedId' already exists!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'CLIENT_GENERATED_ID_ALREADY_EXISTS',
            status: 409,
            title: 'Client generated ID already exists',
            context: ['id' => ErrorContextType::Str],
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/id',
            feature: ErrorFeature::ClientGeneratedIds,
            description: 'A resource of this type already has the `id` the create request supplied. Generate a new id and send the request again, or update the existing resource instead.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['id' => $this->clientGeneratedId],
                source: ErrorSource::fromPointer('/data/id'),
            ),
        ];
    }
}
