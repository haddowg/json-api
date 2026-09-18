<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\ErrorSource;

final class InclusionUnsupported extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('Inclusion is not supported!', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'INCLUSION_UNSUPPORTED',
            status: 400,
            title: 'Inclusion is unsupported',
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: 'Inclusion is not supported by the endpoint!',
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
