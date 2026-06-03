<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * The per-server registry mapping a JSON:API resource type to its
 * {@see AbstractResource} (the Resource class, default serializer + hydrator) and
 * any optional serializer / hydrator overrides. Lookups resolve an override first
 * and fall back to the Resource class.
 *
 * The registry is itself the {@see SerializerResolverInterface} relationships use:
 * when it hands out an {@see AbstractResource} serializer it injects itself, so a
 * relationship can serialize related resources of any registered type.
 *
 * Registration takes class-strings and the resource's `static $type` keys the
 * entry — read **without** instantiating the class. Instances are built **lazily**
 * on first lookup, through an optional injected resolver (a `callable(class-string):
 * object` or a PSR-11 container, normalised to a `\Closure`); with no resolver the
 * registry falls back to plain `new $class()`. Registering two resources with the
 * same type is a configuration error.
 */
final class ResourceRegistry implements SerializerResolverInterface
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
     * The injected factory used to build registered classes lazily, or null to
     * fall back to plain `new $class()`. Always a `\Closure(class-string): object`
     * (a PSR-11 container is normalised to one by the caller).
     *
     * @var (\Closure(class-string): object)|null
     */
    private ?\Closure $resolver = null;

    /**
     * Sets (or clears) the lazy instantiation factory. Resolved instances are
     * cached, so changing the resolver after a type has been looked up does not
     * re-resolve that type.
     *
     * @param (\Closure(class-string): object)|null $resolver
     */
    public function setResolver(?\Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * Registers a Resource class for its declared `static $type`, with optional
     * serializer / hydrator overrides. The type is read statically from
     * `$resource::$type` — the class is **not** instantiated here.
     *
     * @param class-string<AbstractResource>         $resource
     * @param class-string<SerializerInterface>|null $serializer
     * @param class-string<HydratorInterface>|null   $hydrator
     *
     * @throws \LogicException when the resource's type is empty or already registered
     */
    public function register(string $resource, ?string $serializer = null, ?string $hydrator = null): void
    {
        $type = $resource::$type;

        if ($type === '') {
            throw new \LogicException(\sprintf('Resource "%s" must declare a non-empty $type.', $resource));
        }

        $this->guardUnregistered($type);

        $this->entries[$type] = new Entry($resource, $serializer, $hydrator, $type);
    }

    /**
     * Registers a bare serializer + hydrator pair under an explicit `$type`, with
     * no Resource class. At least one of the two must be supplied. A bare pair has
     * no Resource fallback: a missing concern (or any `resourceFor()` lookup)
     * throws {@see NoResourceRegistered}.
     *
     * @param class-string<SerializerInterface>|null $serializer
     * @param class-string<HydratorInterface>|null   $hydrator
     *
     * @throws \LogicException when `$type` is empty, already registered, or neither class is given
     */
    public function registerSerializerHydrator(string $type, ?string $serializer = null, ?string $hydrator = null): void
    {
        if ($type === '') {
            throw new \LogicException('A bare serializer/hydrator pair must be registered under a non-empty $type.');
        }

        if ($serializer === null && $hydrator === null) {
            throw new \LogicException(\sprintf('A bare pair for type "%s" must supply a serializer, a hydrator, or both.', $type));
        }

        $this->guardUnregistered($type);

        $this->entries[$type] = new Entry(null, $serializer, $hydrator, $type);
    }

    public function has(string $type): bool
    {
        return isset($this->entries[$type]);
    }

    public function hasSerializerFor(string $type): bool
    {
        $entry = $this->entries[$type] ?? null;

        return $entry !== null && ($entry->resource !== null || $entry->serializer !== null);
    }

    /**
     * The Resource class for `$type`, built lazily and cached. A bare pair has no
     * Resource class, so this throws {@see NoResourceRegistered}.
     *
     * @throws NoResourceRegistered
     */
    public function resourceFor(string $type): AbstractResource
    {
        $entry = $this->entries[$type] ?? throw new NoResourceRegistered($type);

        if ($entry->resource === null) {
            throw new NoResourceRegistered($type);
        }

        $resource = $this->resourceInstances[$type] ??= $this->makeResource($entry->resource);
        $resource->setSerializerResolver($this);

        return $resource;
    }

    /**
     * The serializer for `$type`: the registered override, else the Resource class.
     * For a bare pair, only the explicit serializer applies — there is no Resource
     * fallback.
     *
     * @throws NoResourceRegistered
     */
    public function serializerFor(string $type): SerializerInterface
    {
        $entry = $this->entries[$type] ?? throw new NoResourceRegistered($type);

        if ($entry->serializer !== null) {
            return $this->serializerInstances[$type] ??= $this->makeSerializer($entry->serializer);
        }

        if ($entry->resource === null) {
            throw new NoResourceRegistered($type);
        }

        return $this->resourceFor($type);
    }

    /**
     * The hydrator for `$type`: the registered override, else the Resource class.
     * For a bare pair, only the explicit hydrator applies — there is no Resource
     * fallback.
     *
     * @throws NoResourceRegistered
     */
    public function hydratorFor(string $type): HydratorInterface
    {
        $entry = $this->entries[$type] ?? throw new NoResourceRegistered($type);

        if ($entry->hydrator !== null) {
            return $this->hydratorInstances[$type] ??= $this->makeHydrator($entry->hydrator);
        }

        if ($entry->resource === null) {
            throw new NoResourceRegistered($type);
        }

        return $this->resourceFor($type);
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

    /**
     * @throws \LogicException when `$type` is already registered
     */
    private function guardUnregistered(string $type): void
    {
        if (isset($this->entries[$type])) {
            throw new \LogicException(\sprintf('A resource is already registered for type "%s".', $type));
        }
    }

    /**
     * Builds a registered class through the injected resolver, or plain `new` when
     * none is injected.
     *
     * @param class-string $class
     */
    private function instantiate(string $class): object
    {
        return $this->resolver !== null ? ($this->resolver)($class) : new $class();
    }

    /**
     * @param class-string<AbstractResource> $class
     */
    private function makeResource(string $class): AbstractResource
    {
        $instance = $this->instantiate($class);

        if (!$instance instanceof AbstractResource) {
            throw new \LogicException(\sprintf(
                'The resolver returned %s for "%s", which is not a %s.',
                \get_debug_type($instance),
                $class,
                AbstractResource::class,
            ));
        }

        return $instance;
    }

    /**
     * @param class-string<SerializerInterface> $class
     */
    private function makeSerializer(string $class): SerializerInterface
    {
        $instance = $this->instantiate($class);

        if (!$instance instanceof SerializerInterface) {
            throw new \LogicException(\sprintf(
                'The resolver returned %s for "%s", which is not a %s.',
                \get_debug_type($instance),
                $class,
                SerializerInterface::class,
            ));
        }

        return $instance;
    }

    /**
     * @param class-string<HydratorInterface> $class
     */
    private function makeHydrator(string $class): HydratorInterface
    {
        $instance = $this->instantiate($class);

        if (!$instance instanceof HydratorInterface) {
            throw new \LogicException(\sprintf(
                'The resolver returned %s for "%s", which is not a %s.',
                \get_debug_type($instance),
                $class,
                HydratorInterface::class,
            ));
        }

        return $instance;
    }
}
