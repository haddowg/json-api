<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class SortParamUnrecognized extends AbstractJsonApiException
{
    public function __construct(public readonly string $sortParam)
    {
        parent::__construct("Sorting parameter '$sortParam' , can't be recognized!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'SORTING_UNRECOGNIZED',
                title: 'Sorting paramter is unrecognized',
                detail: "Sorting parameter '$this->sortParam' can't be recognized by the endpoint!",
                source: ErrorSource::fromParameter('sort'),
            ),
        ];
    }
}
