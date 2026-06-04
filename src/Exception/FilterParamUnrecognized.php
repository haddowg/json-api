<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class FilterParamUnrecognized extends AbstractJsonApiException
{
    public function __construct(public readonly string $filterParam)
    {
        parent::__construct("Filtering parameter '$filterParam' can't be recognized!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'FILTERING_UNRECOGNIZED',
                title: 'Filtering parameter is unrecognized',
                detail: "Filtering parameter '$this->filterParam' can't be recognized by the endpoint!",
                source: ErrorSource::fromParameter("filter[$this->filterParam]"),
            ),
        ];
    }
}
