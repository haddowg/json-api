<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;

/**
 * Thrown when a serializer or hydrator is requested for a resource type that no
 * registered resource (schema) or override covers. A **server configuration
 * error** — the routing or registration is incomplete — so it renders as a 500.
 */
final class NoResourceRegistered extends AbstractJsonApiException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct(\sprintf('No resource is registered for type "%s".', $type), 500);
    }

    public function getErrors(): array
    {
        return [new Error(
            status: '500',
            code: 'NO_RESOURCE_REGISTERED',
            title: 'No resource registered',
            detail: $this->getMessage(),
        )];
    }
}
