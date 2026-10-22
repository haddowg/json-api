<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception\Fixture;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * An application's own described error that tells API consumers what it means: the
 * descriptor carries a description and an example detail for the projected document.
 */
final class SubscriptionExpired extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(private readonly string $plan)
    {
        parent::__construct(\sprintf('The %s subscription has expired.', $plan), self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'SUBSCRIPTION_EXPIRED',
            status: 402,
            title: 'Subscription expired',
            description: 'The account\'s subscription has lapsed. Renew it, then retry the request.',
            exampleDetail: 'The premium subscription has expired.',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage(), context: ['plan' => $this->plan])];
    }
}
