<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class SortParamUnrecognized extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $sortParam)
    {
        parent::__construct("Sorting parameter '$sortParam' can't be recognized!", self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'SORTING_UNRECOGNIZED',
            status: 400,
            title: 'Sorting parameter is unrecognized',
            context: ['param' => ErrorContextType::Str],
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: "Sorting parameter '$this->sortParam' can't be recognized by the endpoint!",
                context: ['param' => $this->sortParam],
                source: ErrorSource::fromParameter('sort'),
            ),
        ];
    }
}
