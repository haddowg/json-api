<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

final class TopLevelMemberNotAllowed extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct(
            'If a document does not contain a top-level "data" key, the "included" member must not be present either.',
            400,
        );
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'TOP_LEVEL_MEMBER_NOT_ALLOWED',
                title: 'Top-level member is not allowed',
                detail: 'If a document does not contain a top-level "data" key, the "included" member must not be present either.',
            ),
        ];
    }
}
