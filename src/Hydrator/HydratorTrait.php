<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Schema\ResourceIdentifier;

/**
 * Core hydration logic shared by all hydrator classes.
 *
 * Helper methods are instance methods (not static); subclasses call them via
 * `$this->`. The abstract methods declare the contracts that concrete hydrators
 * must implement.
 *
 */
trait HydratorTrait
{
    /**
     * Returns the resource types the hydrator accepts.
     *
     * When a resource arrives whose type is not in this list a
     * {@see ResourceTypeUnacceptable} exception is raised.
     *
     * @return list<string>
     */
    abstract protected function getAcceptedTypes(): array;

    /**
     * Returns the attribute hydrators keyed by attribute name.
     *
     * Each callable receives `($domainObject, $value, $data, $attributeName)`.
     * It may mutate `$domainObject` by reference **or** return the updated
     * object; if a non-null/non-false value is returned it becomes the new
     * domain object.
     *
     * @param mixed $domainObject
     * @return array<string, callable>
     */
    abstract protected function getAttributeHydrator(mixed $domainObject): array;

    /**
     * Returns the relationship hydrators keyed by relationship name.
     *
     * Each callable receives `($domainObject, $relationshipObject, $data, $relationshipName)`.
     * The `$relationshipObject` is a {@see ToOneRelationship} or
     * {@see ToManyRelationship}. The callable may type-hint the second parameter
     * to declare the expected cardinality; a mismatch raises
     * {@see RelationshipTypeInappropriate}.
     *
     * @param mixed $domainObject
     * @return array<string, callable>
     */
    abstract protected function getRelationshipHydrator(mixed $domainObject): array;

    /**
     * Validates the `type` member of a resource data array.
     *
     * @param array<string, mixed> $data
     *
     * @throws ResourceTypeMissing
     * @throws ResourceTypeUnacceptable
     */
    protected function validateType(array $data): void
    {
        if (empty($data['type'])) {
            throw new ResourceTypeMissing();
        }

        $type = $data['type'];
        $acceptedTypes = $this->getAcceptedTypes();

        if (\is_string($type) === false) {
            throw new ResourceTypeUnacceptable(\gettype($type), $acceptedTypes);
        }

        if (\in_array($type, $acceptedTypes, true) === false) {
            throw new ResourceTypeUnacceptable($type, $acceptedTypes);
        }
    }

    /**
     * Hydrates the `attributes` member of the resource data into `$domainObject`.
     *
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     */
    protected function hydrateAttributes(mixed $domainObject, array $data): mixed
    {
        $attributes = $data['attributes'] ?? null;
        if (empty($attributes) || \is_array($attributes) === false) {
            return $domainObject;
        }

        $attributeHydrator = $this->getAttributeHydrator($domainObject);
        foreach ($attributeHydrator as $attribute => $hydrator) {
            if (\array_key_exists($attribute, $attributes) === false) {
                continue;
            }

            $result = $hydrator($domainObject, $attributes[$attribute], $data, $attribute);
            if ($result) {
                $domainObject = $result;
            }
        }

        return $domainObject;
    }

    /**
     * Hydrates the `relationships` member of the resource data into `$domainObject`.
     *
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     *
     * @throws JsonApiException
     */
    protected function hydrateRelationships(mixed $domainObject, array $data): mixed
    {
        $relationships = $data['relationships'] ?? null;
        if (empty($relationships) || \is_array($relationships) === false) {
            return $domainObject;
        }

        $relationshipHydrator = $this->getRelationshipHydrator($domainObject);
        foreach ($relationshipHydrator as $relationship => $hydrator) {
            if (isset($relationships[$relationship]) === false) {
                continue;
            }

            $relationshipData = $relationships[$relationship];
            if (\is_array($relationshipData) === false) {
                continue;
            }

            /** @var array<string, mixed> $relationshipData */
            $domainObject = $this->doHydrateRelationship(
                $domainObject,
                $relationship,
                $hydrator,
                $relationshipData,
                $data,
            );
        }

        return $domainObject;
    }

    /**
     * Hydrates a single relationship entry into `$domainObject`.
     *
     * @param mixed $domainObject
     * @param array<string, mixed>|null $relationshipData
     * @param array<string, mixed>|null $data
     * @return mixed
     *
     * @throws JsonApiException
     */
    protected function doHydrateRelationship(
        mixed $domainObject,
        string $relationshipName,
        callable $hydrator,
        ?array $relationshipData,
        ?array $data,
    ): mixed {
        $relationshipObject = $this->createRelationship($relationshipData);

        if ($relationshipObject !== null) {
            $result = $this->getRelationshipHydratorResult(
                $relationshipName,
                $hydrator,
                $domainObject,
                $relationshipObject,
                $data,
            );

            if ($result !== null) {
                $domainObject = $result;
            }
        }

        return $domainObject;
    }

    /**
     * Invokes the relationship callable and validates cardinality before calling it.
     *
     * @param mixed $domainObject
     * @param ToOneRelationship|ToManyRelationship $relationshipObject
     * @param array<string, mixed>|null $data
     * @return mixed
     *
     * @throws RelationshipTypeInappropriate
     */
    protected function getRelationshipHydratorResult(
        string $relationshipName,
        callable $hydrator,
        mixed $domainObject,
        ToOneRelationship|ToManyRelationship $relationshipObject,
        ?array $data,
    ): mixed {
        $relationshipType = $this->getRelationshipType($relationshipObject);
        $expectedRelationshipType = $this->getRelationshipType($this->getArgumentTypeHintFromCallable($hydrator));

        if ($expectedRelationshipType !== '' && $relationshipType !== $expectedRelationshipType) {
            throw new RelationshipTypeInappropriate(
                $relationshipName,
                $relationshipType,
                $expectedRelationshipType,
            );
        }

        $value = $hydrator($domainObject, $relationshipObject, $data, $relationshipName);
        if ($value) {
            return $value;
        }

        return $domainObject;
    }

    /**
     * Extracts the class name of the second parameter of a callable, if it has one.
     */
    protected function getArgumentTypeHintFromCallable(callable $callable): ?string
    {
        $function = &$callable;
        $reflection = new \ReflectionFunction(\Closure::fromCallable($function));
        $arguments = $reflection->getParameters();
        $type = isset($arguments[1]) ? $arguments[1]->getType() : null;

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return null;
    }

    /**
     * Returns the relationship kind string for the given object or class name.
     *
     * @param ToOneRelationship|ToManyRelationship|string|null $object
     */
    protected function getRelationshipType(ToOneRelationship|ToManyRelationship|string|null $object): string
    {
        if ($object instanceof ToOneRelationship || $object === ToOneRelationship::class) {
            return 'to-one';
        }

        if ($object instanceof ToManyRelationship || $object === ToManyRelationship::class) {
            return 'to-many';
        }

        return '';
    }

    /**
     * Parses a relationship data fragment into the appropriate relationship value object.
     *
     * Returns `null` if the fragment has no `data` key (links-only relationship).
     *
     * @param array<string, mixed>|null $relationship
     */
    private function createRelationship(?array $relationship): ToOneRelationship|ToManyRelationship|null
    {
        if ($relationship === null || \array_key_exists('data', $relationship) === false) {
            return null;
        }

        $relationshipData = $relationship['data'];

        if ($relationshipData === null) {
            return new ToOneRelationship();
        }

        if (\is_array($relationshipData) === false) {
            return null;
        }

        if ($this->isAssociativeArray($relationshipData)) {
            /** @var array<string, mixed> $relationshipData */
            return new ToOneRelationship(ResourceIdentifier::fromArray($relationshipData));
        }

        $identifiers = [];
        foreach ($relationshipData as $item) {
            if (\is_array($item) === false) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $identifiers[] = ResourceIdentifier::fromArray($item);
        }

        return new ToManyRelationship($identifiers);
    }

    /**
     * @param array<mixed> $array
     */
    private function isAssociativeArray(array $array): bool
    {
        return (bool) \count(\array_filter(\array_keys($array), '\is_string'));
    }
}
