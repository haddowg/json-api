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
            exampleDetail: 'A client generated ID must be used!',
            source: ErrorSourceShape::Pointer,
            exampleSource: '/data/id',
            feature: ErrorFeature::ClientGeneratedIds,
            description: 'This resource type expects the client to choose the id, and the create request supplied none. Add `data.id` in the format the API documents and send the request again.',
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
