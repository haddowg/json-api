<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class QueryParamUnrecognized extends AbstractJsonApiException
{
    public function __construct(public readonly string $unrecognizedQueryParam)
    {
        parent::__construct("Query parameter '$unrecognizedQueryParam' can't be recognized!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'QUERY_PARAM_UNRECOGNIZED',
                title: 'Query parameter is unrecognized',
                detail: "Query parameter '$this->unrecognizedQueryParam' can't be recognized by the endpoint!",
                source: ErrorSource::fromParameter($this->unrecognizedQueryParam),
            ),
        ];
    }
}
