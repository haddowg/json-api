<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception\Fixture;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ResourceNotFound;

/**
 * Claims the code {@see ResourceNotFound} already publishes, with a different status and
 * title — the collision the catalogue must refuse rather than resolve.
 */
final class RivalResourceNotFound extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('Nothing there.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'RESOURCE_NOT_FOUND',
            status: 410,
            title: 'Gone, actually',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
