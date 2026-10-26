<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorSourceShape;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * An application's own `400` that always names the offending query parameter but declares
 * no example of one, so the error response it joins has no example to show.
 */
final class QuotaParameterRejected extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The quota parameter was rejected.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'QUOTA_PARAMETER_REJECTED',
            status: 400,
            title: 'Quota parameter rejected',
            source: ErrorSourceShape::Parameter,
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage(), source: ErrorSource::fromParameter('quota'))];
    }
}
