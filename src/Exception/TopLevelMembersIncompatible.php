<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class TopLevelMembersIncompatible extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('The members "data" and "errors" cannot coexist in the same document', 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'TOP_LEVEL_MEMBERS_INCOMPATIBLE',
                title: 'Top-level members are incompatible',
                detail: 'The members "data" and "errors" cannot coexist in the same document',
            ),
        ];
    }
}
