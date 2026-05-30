<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class FullReplacementProhibited extends AbstractJsonApiException
{
    public function __construct(public readonly string $relationshipName)
    {
        parent::__construct("Full replacement of relationship '$relationshipName' is prohibited!", 403);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '403',
                code: 'FULL_REPLACEMENT_PROHIBITED',
                title: 'Full replacement is prohibited',
                detail: $this->getMessage(),
                source: ErrorSource::fromPointer("/data/relationships/$this->relationshipName"),
            ),
        ];
    }
}
