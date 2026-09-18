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
            context: ['param' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
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
