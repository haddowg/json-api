<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Data;

/**
 * Contract for the serialization data accumulator that collects primary and included
 * resources during a serialization pass.
 *
 * @internal
 */
interface DataInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getResource(string $type, string $id): ?array;

    public function hasPrimaryResources(): bool;

    public function hasPrimaryResource(string $type, string $id): bool;

    public function hasIncludedResources(): bool;

    public function hasIncludedResource(string $type, string $id): bool;

    /**
     * @param iterable<array<string, mixed>> $transformedResources
     * @return $this
     */
    public function setPrimaryResources(iterable $transformedResources): static;

    /**
     * @param array<string, mixed> $transformedResource
     * @return $this
     */
    public function addPrimaryResource(array $transformedResource): static;

    /**
     * @param iterable<array<string, mixed>> $transformedResources
     * @return $this
     */
    public function setIncludedResources(iterable $transformedResources): static;

    /**
     * @param array<string, mixed> $transformedResource
     * @return $this
     */
    public function addIncludedResource(array $transformedResource): static;

    /**
     * @return iterable<array<string, mixed>>|null
     */
    public function transformPrimaryData(): ?iterable;

    /**
     * @return iterable<array<string, mixed>>
     */
    public function transformIncluded(): iterable;
}
