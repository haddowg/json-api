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
            context: ['param' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
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
