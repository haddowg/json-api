<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Hydrator\HydratorResolverInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\SerializerResolverAwareInterface;
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
 *
 * @internal package-internal registry; consumers register via the fluent
 *           {@see Server::register()} / {@see Server::registerSerializerHydrator()}
 *           and resolve a type via {@see Server::resourceFor()},
 *           {@see Server::serializerFor()} and {@see Server::hydratorFor()}
 */
final class ResourceRegistry implements SerializerResolverInterface, HydratorResolverInterface
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
     * The storage-aware predicate that answers whether a relation's linkage is
     * already loaded, or null when none is injected (standalone core treats
     * every relation as loaded). Threaded down from the {@see Server}, the same
     * way the lazy {@see $resolver} is.
     */
    private ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface $relationshipLoadState = null;

    /**
     * The storage-aware resolver that supplies a countable relation's cardinality
     * (`meta.total`), or null when none is injected (standalone core emits no
     * count). Threaded down from the {@see Server}, the same way the lazy
     * {@see $resolver} is.
     */
    private ?\haddowg\JsonApi\Serializer\RelationshipCountInterface $relationshipCount = null;

    /**
     * The storage-aware resolver that supplies a to-many relation's page-1
     * pagination state (the relationship-object pagination links) under the
     * Relationship Queries profile, or null when none is injected (standalone core
     * emits no such links). Threaded down from the {@see Server}, the same way the
     * lazy {@see $resolver} is.
     */
    private ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface $relationshipPagination = null;

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
     * Sets (or clears) the relationship load-state predicate consulted when a
     * relation opts in via {@see \haddowg\JsonApi\Resource\Field\RelationInterface::linkageOnlyWhenLoaded()}.
     */
    public function setRelationshipLoadState(?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface $relationshipLoadState): void
    {
        $this->relationshipLoadState = $relationshipLoadState;
    }

    public function relationshipLoadState(): ?\haddowg\JsonApi\Serializer\RelationshipLoadStateInterface
    {
        return $this->relationshipLoadState;
    }

    /**
     * Sets (or clears) the relationship-count resolver consulted for a relation
     * that is {@see \haddowg\JsonApi\Resource\Field\RelationInterface::isCountable()}
     * and named in the request's `?withCount`.
     */
    public function setRelationshipCount(?\haddowg\JsonApi\Serializer\RelationshipCountInterface $relationshipCount): void
    {
        $this->relationshipCount = $relationshipCount;
    }

    public function relationshipCount(): ?\haddowg\JsonApi\Serializer\RelationshipCountInterface
    {
        return $this->relationshipCount;
    }

    /**
     * Sets (or clears) the relationship-pagination resolver consulted for a
     * to-many relation when the Relationship Queries profile is negotiated.
     */
    public function setRelationshipPagination(?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface $relationshipPagination): void
    {
        $this->relationshipPagination = $relationshipPagination;
    }

    public function relationshipPagination(): ?\haddowg\JsonApi\Serializer\RelationshipPaginationInterface
    {
        return $this->relationshipPagination;
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

    /**
     * Whether `$type` has a Resource class (vs a bare serializer/hydrator pair).
     * The presence-check mirror of {@see resourceFor()}, so a caller can branch on
     * a standalone-registered type without catching {@see NoResourceRegistered}.
     */
    public function hasResourceFor(string $type): bool
    {
        $entry = $this->entries[$type] ?? null;

        return $entry !== null && $entry->resource !== null;
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

        return $this->resourceInstances[$type] ??= $this->makeResource($entry->resource);
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

    public function hasHydratorFor(string $type): bool
    {
        $entry = $this->entries[$type] ?? null;

        return $entry !== null && ($entry->resource !== null || $entry->hydrator !== null);
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
     * Injects this registry (it is the {@see SerializerResolverInterface}) into a
     * resolved instance that opts in via {@see SerializerResolverAwareInterface},
     * so it can render relationships. An instance that does not implement the
     * interface is left untouched.
     *
     * @template T of object
     *
     * @param T $instance
     *
     * @return T
     */
    private function injectResolver(object $instance): object
    {
        if ($instance instanceof SerializerResolverAwareInterface) {
            $instance->setSerializerResolver($this);
        }

        return $instance;
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

        return $this->injectResolver($instance);
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

        return $this->injectResolver($instance);
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
