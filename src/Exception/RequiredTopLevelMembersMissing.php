<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class RequiredTopLevelMembersMissing extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct(
            'A document must contain at least one of the following top-level members: "data", "errors", "meta"',
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'REQUIRED_TOP_LEVEL_MEMBERS_MISSING',
                title: 'Required top-level members are missing',
                detail: 'A document must contain at least one of the following top-level members: "data", "errors", "meta"',
            ),
        ];
    }
}
