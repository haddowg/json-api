<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * Resolves the path of every {@see TraversesPath} filter against the relation graph of
 * the type the filters run against, so each data layer reads what the path ends on
 * instead of guessing it.
 *
 * Each segment is matched to a declared relation by its storage name, the relation's
 * `column()`, which is the name a data layer traverses. When every segment matches a
 * relation, the path ends on that last relation and the filter compares the related
 * resource's id. When the walk reaches a segment that is not a relation, or an
 * intermediate polymorphic relation it cannot follow, the path ends on a field and the
 * filter behaves exactly as an unresolved one.
 *
 * A data layer resolves the filters it is about to apply, after matching requested keys,
 * and may resolve the declared ones at warm-up to fail fast. Resolving is idempotent.
 */
final class ThroughPathResolver
{
    /**
     * @param \Closure(string): (iterable<FieldInterface>|null) $fieldsOf a type's field inventory, or `null` when the type has none
     */
    public function __construct(
        private readonly \Closure $fieldsOf,
    ) {}

    /**
     * A resolver over the registered resources, reading each related type's inventory
     * from its {@see AbstractResource}.
     */
    public static function forResolver(SerializerResolverInterface $resolver): self
    {
        return new self(static function (string $type) use ($resolver): ?array {
            if (!$resolver->hasSerializerFor($type)) {
                return null;
            }

            $serializer = $resolver->serializerFor($type);

            return $serializer instanceof AbstractResource ? $serializer->allFields() : null;
        });
    }

    /**
     * `$filters` with every path-traversing filter resolved against `$fields`, the
     * inventory of the type the filters run against. A group's children are resolved in
     * place; a filter family resolves its filters per request, so resolve what it
     * returns instead.
     *
     * @param list<FilterInterface>    $filters
     * @param iterable<FieldInterface> $fields
     *
     * @return list<FilterInterface>
     *
     * @throws \LogicException when a path ends on a polymorphic relation, its related type is not registered, or the filter cannot compare what the path ends on
     */
    public function resolveAll(array $filters, iterable $fields): array
    {
        $fields = self::listed($fields);

        return \array_map(fn(FilterInterface $filter): FilterInterface => $this->resolve($filter, $fields), $filters);
    }

    /**
     * `$filter` resolved against `$fields`, the inventory of the type it runs against.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when a path ends on a polymorphic relation, its related type is not registered, or the filter cannot compare what the path ends on
     */
    public function resolve(FilterInterface $filter, iterable $fields): FilterInterface
    {
        if ($filter instanceof TraversesPath) {
            return $filter->resolvedPath() !== null
                ? $filter
                : $filter->withResolvedPath($this->path($filter->key(), $filter->throughPath(), $fields));
        }

        if ($filter instanceof WhereGroup) {
            $children = $this->resolveAll($filter->children, $fields);

            return $children === $filter->children
                ? $filter
                : new ($filter::class)($filter->key, $children, $filter->constraints, $filter->description, $filter->hasExample, $filter->example, $filter->deprecated);
        }

        return $filter;
    }

    /**
     * Resolves one dotted path against `$fields`.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when the path ends on a polymorphic relation or its related type is not registered
     */
    public function path(string $key, string $path, iterable $fields): ThroughPath
    {
        $segments = ThroughPath::split($path);
        $last = \count($segments) - 1;
        $fields = self::listed($fields);

        foreach ($segments as $index => $segment) {
            $relation = self::relationStoredAs($fields, $segment);
            if ($relation === null) {
                return ThroughPath::toField($path);
            }

            $polymorphic = $relation instanceof PolymorphicRelationInterface || \count($relation->relatedTypes()) !== 1;

            if ($index === $last) {
                if ($polymorphic) {
                    throw new \LogicException(\sprintf(
                        'Filter "%s" ends its path "%s" on the polymorphic relation "%s"; a relationship leaf compares one related type\'s id, so it must be monomorphic.',
                        $key,
                        $path,
                        $relation->name(),
                    ));
                }

                $relatedType = $relation->relatedTypes()[0];
                $relatedFields = ($this->fieldsOf)($relatedType)
                    ?? throw new \LogicException(\sprintf(
                        'Filter "%s" ends its path "%s" on relation "%s", whose related type "%s" is not a registered resource, so its id cannot be decoded.',
                        $key,
                        $path,
                        $relation->name(),
                        $relatedType,
                    ));

                return new ThroughPath($path, $segments, $relation, $relatedType, self::idField(self::listed($relatedFields)));
            }

            if ($polymorphic) {
                return ThroughPath::toField($path);
            }

            $next = ($this->fieldsOf)($relation->relatedTypes()[0]);
            if ($next === null) {
                return ThroughPath::toField($path);
            }
            $fields = self::listed($next);
        }

        return ThroughPath::toField($path);
    }

    /**
     * @param iterable<FieldInterface> $fields
     *
     * @return list<FieldInterface>
     */
    private static function listed(iterable $fields): array
    {
        return \is_array($fields) ? \array_values($fields) : \iterator_to_array($fields, false);
    }

    /**
     * @param iterable<FieldInterface> $fields
     */
    private static function relationStoredAs(iterable $fields, string $segment): ?RelationInterface
    {
        foreach ($fields as $field) {
            if ($field instanceof RelationInterface && $field->column() === $segment) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param iterable<FieldInterface> $fields
     */
    private static function idField(iterable $fields): ?Id
    {
        foreach ($fields as $field) {
            if ($field instanceof Id) {
                return $field;
            }
        }

        return null;
    }
}
