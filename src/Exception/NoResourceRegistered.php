<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * Thrown when a serializer or hydrator is requested for a resource type that no
 * registered resource (schema) or override covers. A **server configuration
 * error** — the routing or registration is incomplete — so it renders as a 500.
 */
final class NoResourceRegistered extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type)
    {
        parent::__construct(\sprintf('No resource is registered for type "%s".', $type), self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'NO_RESOURCE_REGISTERED',
            status: 500,
            title: 'No resource registered',
            context: ['type' => ErrorContextType::Str],
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(
            detail: $this->getMessage(),
            context: ['type' => $this->type],
        )];
    }
}
