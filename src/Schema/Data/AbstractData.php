<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Data;

/**
 * Mutable accumulator that collects primary and included resources during a serialization pass.
 * Resources are stored in a single flat map keyed by "type.id"; separate key maps track which
 * resources belong to primary vs included data, with primary taking precedence over included
 * (a resource promoted to primary is removed from the included set).
 *
 * @internal
 */
abstract class AbstractData implements DataInterface
{
    /**
     * Flat store of all known resources, keyed by "type.id".
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $resources = [];

    /**
     * References into $resources for the primary data set, keyed by "type.id".
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $primaryKeys = [];

    /**
     * References into $resources for the included data set, keyed by "type.id".
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $includedKeys = [];

    /**
     * @return array<string, mixed>|null
     */
    public function getResource(string $type, string $id): ?array
    {
        return $this->resources["{$type}.{$id}"] ?? null;
    }

    public function hasPrimaryResources(): bool
    {
        return $this->primaryKeys !== [];
    }

    public function hasPrimaryResource(string $type, string $id): bool
    {
        return isset($this->primaryKeys["{$type}.{$id}"]);
    }

    public function hasIncludedResources(): bool
    {
        return $this->includedKeys !== [];
    }

    public function hasIncludedResource(string $type, string $id): bool
    {
        return isset($this->includedKeys["{$type}.{$id}"]);
    }

    /**
     * @param iterable<array<string, mixed>> $transformedResources
     * @return $this
     */
    public function setPrimaryResources(iterable $transformedResources): static
    {
        $this->primaryKeys = [];
        foreach ($transformedResources as $resource) {
            $this->addPrimaryResource($resource);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $transformedResource
     * @return $this
     */
    public function addPrimaryResource(array $transformedResource): static
    {
        /** @var string $type */
        $type = $transformedResource['type'];
        /** @var string $id */
        $id = $transformedResource['id'];

        if ($this->hasIncludedResource($type, $id)) {
            unset($this->includedKeys["{$type}.{$id}"]);
        }

        $this->addResourceToPrimaryData($transformedResource);

        return $this;
    }

    /**
     * @param iterable<array<string, mixed>> $transformedResources
     * @return $this
     */
    public function setIncludedResources(iterable $transformedResources): static
    {
        $this->includedKeys = [];
        foreach ($transformedResources as $resource) {
            $this->addIncludedResource($resource);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $transformedResource
     * @return $this
     */
    public function addIncludedResource(array $transformedResource): static
    {
        /** @var string $type */
        $type = $transformedResource['type'];
        /** @var string $id */
        $id = $transformedResource['id'];

        if ($this->hasPrimaryResource($type, $id) === false) {
            $this->addResourceToIncludedData($transformedResource);
        }

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transformIncluded(): array
    {
        return \array_values($this->includedKeys);
    }

    /**
     * @param array<string, mixed> $transformedResource
     */
    protected function addResourceToPrimaryData(array $transformedResource): void
    {
        /** @var string $type */
        $type = $transformedResource['type'];
        /** @var string $id */
        $id = $transformedResource['id'];
        $key = "{$type}.{$id}";

        $this->resources[$key] = $transformedResource;
        $this->primaryKeys[$key] = &$this->resources[$key];
    }

    /**
     * @param array<string, mixed> $transformedResource
     */
    protected function addResourceToIncludedData(array $transformedResource): void
    {
        /** @var string $type */
        $type = $transformedResource['type'];
        /** @var string $id */
        $id = $transformedResource['id'];
        $key = "{$type}.{$id}";

        $this->resources[$key] = $transformedResource;
        $this->includedKeys[$key] = &$this->resources[$key];
    }
}
