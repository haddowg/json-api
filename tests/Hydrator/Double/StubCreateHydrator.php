<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Hydrator\Double;

use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Hydrator\CreateHydratorTrait;
use haddowg\JsonApi\Hydrator\HydratorTrait;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Test double that exercises CreateHydratorTrait in isolation.
 */
final class StubCreateHydrator
{
    use HydratorTrait;
    use CreateHydratorTrait;

    /** Captures the "owner" to-one relationship as hydrated, for assertions. */
    public ?ToOneRelationship $capturedOwner = null;

    public function __construct(
        private readonly bool $isClientGeneratedIdException,
        private readonly string $generatedId,
        private readonly bool $logicException,
    ) {}

    protected function getAcceptedTypes(): array
    {
        return ['user'];
    }

    protected function validateClientGeneratedId(string $clientGeneratedId, JsonApiRequestInterface $request): void
    {
        if ($this->isClientGeneratedIdException) {
            throw new ClientGeneratedIdNotSupported($clientGeneratedId);
        }
    }

    protected function generateId(): string
    {
        return $this->generatedId;
    }

    protected function setId(mixed $domainObject, string $id): mixed
    {
        /** @var array<string, mixed> $domainObject */
        $domainObject['id'] = $id;

        return $domainObject;
    }

    protected function validateRequest(JsonApiRequestInterface $request): void
    {
        if ($this->logicException) {
            throw new \LogicException();
        }
    }

    protected function getAttributeHydrator(mixed $domainObject): array
    {
        return [];
    }

    protected function getRelationshipHydrator(mixed $domainObject): array
    {
        return [
            'owner' => function (mixed $domainObject, ToOneRelationship $owner): mixed {
                $this->capturedOwner = $owner;

                return $domainObject;
            },
        ];
    }
}
