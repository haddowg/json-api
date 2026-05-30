<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class SortingUnsupported extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Sorting is not supported!', 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'SORTING_UNSUPPORTED',
                title: 'Sorting is unsupported',
                detail: 'Sorting is not supported by the endpoint!',
                source: ErrorSource::fromParameter('sort'),
            ),
        ];
    }
}
