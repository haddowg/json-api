<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception\Fixture;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * An application's own described error — a `402` core never defines — standing in for
 * whatever a consumer contributes through an {@see \haddowg\JsonApi\Exception\ErrorCatalogSourceInterface}.
 */
final class PaymentRequired extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('This operation requires an active premium subscription.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'PAYMENT_REQUIRED',
            status: 402,
            title: 'Payment required',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
