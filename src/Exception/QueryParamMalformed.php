<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class QueryParamMalformed extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(
        public readonly string $malformedQueryParam,
        public readonly mixed $malformedQueryParamValue,
    ) {
        parent::__construct("Query parameter '$malformedQueryParam' is malformed!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'QUERY_PARAM_MALFORMED',
            status: 400,
            title: 'Query parameter is malformed',
            exampleDetail: 'Query parameter \'include\' is malformed!',
            context: ['param' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            exampleSource: 'include',
            description: 'A query parameter has the wrong shape, such as `fields` sent without a type key or `include` sent as a list instead of a comma-separated string. Fix the parameter named in `source.parameter`.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Query parameter '$this->malformedQueryParam' is malformed!",
                context: ['param' => $this->malformedQueryParam],
                source: ErrorSource::fromParameter($this->malformedQueryParam),
            ),
        ];
    }
}
