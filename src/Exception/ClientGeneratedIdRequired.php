<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class ClientGeneratedIdRequired extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('A client generated ID must be used!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'CLIENT_GENERATED_ID_REQUIRED',
            status: 403,
            title: 'Required client generated ID',
            source: ErrorSourceShape::Pointer,
            feature: ErrorFeature::ClientGeneratedIds,
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
