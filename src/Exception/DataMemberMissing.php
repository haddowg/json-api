<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class DataMemberMissing extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct("Missing `data` member at the document's top level!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'DATA_MEMBER_MISSING',
            status: 400,
            title: "Missing `data` member at the document's top level",
            feature: ErrorFeature::Writes,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer(''),
            ),
        ];
    }
}
