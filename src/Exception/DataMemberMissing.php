<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class DataMemberMissing extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct("Missing `data` member at the document's top level!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'DATA_MEMBER_MISSING',
                title: "Missing `data` member at the document's top level",
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer(''),
            ),
        ];
    }
}
