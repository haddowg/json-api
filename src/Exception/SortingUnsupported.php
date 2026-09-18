<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class SortingUnsupported extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('Sorting is not supported!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'SORTING_UNSUPPORTED',
            status: 400,
            title: 'Sorting is unsupported',
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'Sorting is not supported by the endpoint!',
                source: ErrorSource::fromParameter('sort'),
            ),
        ];
    }
}
