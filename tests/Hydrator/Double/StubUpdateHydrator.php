<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator\Double;

use haddowg\JsonApi\Hydrator\HydratorTrait;
use haddowg\JsonApi\Hydrator\UpdateHydratorTrait;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Test double that exercises UpdateHydratorTrait in isolation.
 */
final class StubUpdateHydrator
{
    use HydratorTrait;
    use UpdateHydratorTrait;

    public function __construct(private readonly bool $validationException = false) {}

    protected function getAcceptedTypes(): array
    {
        return ['user'];
    }

    protected function setId(mixed $domainObject, string $id): mixed
    {
        /** @var array<string, mixed> $domainObject */
        $domainObject['id'] = $id;

        return $domainObject;
    }

    protected function validateRequest(JsonApiRequestInterface $request): void
    {
        if ($this->validationException) {
            throw new \LogicException();
        }
    }

    protected function getAttributeHydrator(mixed $domainObject): array
    {
        return [];
    }

    protected function getRelationshipHydrator(mixed $domainObject): array
    {
        return [];
    }
}
