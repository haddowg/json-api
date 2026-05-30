<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator\Double;

use haddowg\JsonApi\Hydrator\AbstractHydrator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Test double for AbstractHydrator.
 *
 * Stores accepted types, attribute hydrators, and relationship hydrators
 * supplied at construction time. ID handling is a no-op so the domain object
 * (an array) is the mutation target in tests.
 */
final class StubHydrator extends AbstractHydrator
{
    /**
     * @param list<string> $acceptedTypes
     * @param array<string, callable> $attributeHydrator
     * @param array<string, callable> $relationshipHydrator
     */
    public function __construct(
        private readonly array $acceptedTypes = [],
        private readonly array $attributeHydrator = [],
        private readonly array $relationshipHydrator = [],
    ) {}

    protected function getAcceptedTypes(): array
    {
        return $this->acceptedTypes;
    }

    protected function validateClientGeneratedId(string $clientGeneratedId, JsonApiRequestInterface $request): void {}

    protected function generateId(): string
    {
        return '1';
    }

    protected function setId(mixed $domainObject, string $id): mixed
    {
        return null;
    }

    protected function validateRequest(JsonApiRequestInterface $request): void {}

    protected function getAttributeHydrator(mixed $domainObject): array
    {
        return $this->attributeHydrator;
    }

    protected function getRelationshipHydrator(mixed $domainObject): array
    {
        return $this->relationshipHydrator;
    }
}
