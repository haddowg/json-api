<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class QueryParamMalformed extends AbstractJsonApiException
{
    public function __construct(
        public readonly string $malformedQueryParam,
        public readonly mixed $malformedQueryParamValue,
    ) {
        parent::__construct("Query parameter '$malformedQueryParam' is malformed!", 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'QUERY_PARAM_MALFORMED',
                title: 'Query parameter is malformed',
                detail: "Query parameter '$this->malformedQueryParam' is malformed!",
                source: ErrorSource::fromParameter($this->malformedQueryParam),
            ),
        ];
    }
}
