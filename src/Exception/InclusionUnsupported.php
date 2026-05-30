<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

final class InclusionUnsupported extends AbstractJsonApiException
{
    public function __construct()
    {
        parent::__construct('Inclusion is not supported!', 400);
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '400',
                code: 'INCLUSION_UNSUPPORTED',
                title: 'Inclusion is unsupported',
                detail: 'Inclusion is not supported by the endpoint!',
                source: ErrorSource::fromParameter('include'),
            ),
        ];
    }
}
