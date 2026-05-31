<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\SerializerResolver;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * The per-server registry mapping a JSON:API resource type to its
 * {@see AbstractResource} (the schema, default serializer + hydrator) and any
 * optional serializer / hydrator overrides. Lookups resolve an override first
 * and fall back to the schema.
 *
 * The registry is itself the {@see SerializerResolver} relationships use: when
 * it hands out an {@see AbstractResource} serializer it injects itself, so a
 * relationship can serialize related resources of any registered type.
 *
 * Registration takes class-strings and instantiates lazily; the resource's
 * `static $type` keys the entry. Registering two resources with the same type is
 * a configuration error.
 */
final class SchemaRegistry implements SerializerResolver
{
    /**
     * @var array<string, Entry>
     */
    private array $entries = [];

    /**
     * @var array<string, AbstractResource>
     */
    private array $resourceInstances = [];

    /**
     * @var array<string, SerializerInterface>
     */
    private array $serializerInstances = [];

    /**
     * @var array<string, HydratorInterface>
     */
    private array $hydratorInstances = [];

    /**
     * @param class-string<AbstractResource>      $resource
     * @param class-string<SerializerInterface>|null $serializer
     * @param class-string<HydratorInterface>|null   $hydrator
     *
     * @throws \LogicException when the resource's type is already registered
     */
    public function register(string $resource, ?string $serializer = null, ?string $hydrator = null): void
    {
        $instance = new $resource();
        $type = $instance::$type;

        if ($type === '') {
            throw new \LogicException(\sprintf('Resource "%s" must declare a non-empty $type.', $resource));
        }

        if (isset($this->entries[$type])) {
            throw new \LogicException(\sprintf('A resource is already registered for type "%s".', $type));
        }

        $this->entries[$type] = new Entry($resource, $serializer, $hydrator);
        $this->resourceInstances[$type] = $instance;
    }

    public function has(string $type): bool
    {
        return isset($this->entries[$type]);
    }

    public function hasSerializerFor(string $type): bool
    {
        return isset($this->entries[$type]);
    }

    /**
     * The schema (fluent resource) for `$type`.
     *
     * @throws NoResourceRegistered
     */
    public function schemaFor(string $type): AbstractResource
    {
        if (!isset($this->entries[$type])) {
            throw new NoResourceRegistered($type);
        }

        $resource = $this->resourceInstances[$type];
        $resource->setSerializerResolver($this);

        return $resource;
    }

    /**
     * The serializer for `$type`: the registered override, else the schema.
     *
     * @throws NoResourceRegistered
     */
    public function serializerFor(string $type): SerializerInterface
    {
        $entry = $this->entries[$type] ?? throw new NoResourceRegistered($type);

        if ($entry->serializer !== null) {
            return $this->serializerInstances[$type] ??= new ($entry->serializer)();
        }

        return $this->schemaFor($type);
    }

    /**
     * The hydrator for `$type`: the registered override, else the schema.
     *
     * @throws NoResourceRegistered
     */
    public function hydratorFor(string $type): HydratorInterface
    {
        $entry = $this->entries[$type] ?? throw new NoResourceRegistered($type);

        if ($entry->hydrator !== null) {
            return $this->hydratorInstances[$type] ??= new ($entry->hydrator)();
        }

        return $this->schemaFor($type);
    }

    /**
     * The registered resource types.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return \array_keys($this->entries);
    }
}
