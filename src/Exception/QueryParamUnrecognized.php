<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class QueryParamUnrecognized extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $unrecognizedQueryParam)
    {
        parent::__construct("Query parameter '$unrecognizedQueryParam' can't be recognized!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'QUERY_PARAM_UNRECOGNIZED',
            status: 400,
            title: 'Query parameter is unrecognized',
            exampleDetail: 'Query parameter \'colour\' can\'t be recognized by the endpoint!',
            context: ['param' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            exampleSource: 'colour',
            description: 'The request used a query parameter this endpoint does not recognize. Remove it, or check its spelling against the parameters the API documents.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Query parameter '$this->unrecognizedQueryParam' can't be recognized by the endpoint!",
                context: ['param' => $this->unrecognizedQueryParam],
                source: ErrorSource::fromParameter($this->unrecognizedQueryParam),
            ),
        ];
    }
}
