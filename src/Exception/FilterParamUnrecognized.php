<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class FilterParamUnrecognized extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $filterParam)
    {
        parent::__construct("Filtering parameter '$filterParam' can't be recognized!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'FILTERING_UNRECOGNIZED',
            status: 400,
            title: 'Filtering parameter is unrecognized',
            context: ['filter' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
            description: 'The request filtered on a key this endpoint does not declare. Filter only on the keys the API documents for this resource type.',
            exampleDetail: 'Filtering parameter \'colour\' can\'t be recognized by the endpoint!',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Filtering parameter '$this->filterParam' can't be recognized by the endpoint!",
                context: ['filter' => $this->filterParam],
                source: ErrorSource::fromParameter("filter[$this->filterParam]"),
            ),
        ];
    }
}
