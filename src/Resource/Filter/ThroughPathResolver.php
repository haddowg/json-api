<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Through\ReachesThrough;
use haddowg\JsonApi\Resource\Through\RelationPath;

/**
 * Resolves the path of every {@see TraversesPath} filter, the relation of every
 * {@see WhereMorph}, and the `->through()` path of every filter and sort reaching through
 * relations ({@see ReachesThrough}), against the relation graph of the type they run
 * against, so each data layer reads what the path ends on instead of guessing it.
 *
 * A `->through()` path names relations by their API names, each relation's `name()`, and
 * the filter's column or the sort's field names a field of the type the path reaches. A
 * {@see TraversesPath} filter's path names them by storage name, as described below.
 *
 * Each segment is matched to a declared relation by its storage name, the relation's
 * `column()`, which is the name a data layer traverses. When several relations share
 * that storage member, the one whose name is the segment is the relation the segment
 * names. When every segment matches a
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
        if (($filter instanceof WhereIdIn || $filter instanceof WhereIdNotIn) && $filter->throughRelations() === null) {
            $filter = $this->relatedIds($filter, self::listed($fields));
        }

        if ($filter instanceof ReachesThrough && ($through = $filter->throughRelations()) !== null) {
            return $filter->resolvedThrough() !== null
                ? $filter
                : $filter->withResolvedThrough($this->relationPath($filter->key(), $through, $fields));
        }

        if ($filter instanceof TraversesPath) {
            return $filter->resolvedPath() !== null
                ? $filter
                : $filter->withResolvedPath($this->path($filter->key(), $filter->throughPath(), $fields));
        }

        if ($filter instanceof WhereMorph) {
            return $filter->resolvedTarget() !== null
                ? $filter
                : $filter->withResolvedTarget($this->morphTarget($filter, $fields));
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
     * An id filter keyed by one of the type's relations, by its API name, compares that
     * relation's related ids, as if it reached through it: `WhereIdIn::make('template')`
     * on a type with a `template` relation filters by the template's id. Keyed `id`, or by
     * a name no relation has, it compares the type's own ids.
     *
     * @param list<FieldInterface> $fields
     *
     * @throws \LogicException when the key names a relation but the column is not `id`, which could mean either
     */
    private function relatedIds(WhereIdIn|WhereIdNotIn $filter, array $fields): WhereIdIn|WhereIdNotIn
    {
        if ($filter->key === 'id') {
            return $filter;
        }

        foreach ($fields as $field) {
            if (!$field instanceof RelationInterface || $field->name() !== $filter->key) {
                continue;
            }

            if ($filter->column !== 'id') {
                throw new \LogicException(\sprintf(
                    '%s filter "%s" is keyed by the relation "%s" but compares the column "%s", so it could mean the relation\'s ids or the type\'s own. Key a filter of the type\'s own ids by another name, or drop the column to compare the relation\'s ids.',
                    (new \ReflectionClass($filter))->getShortName(),
                    $filter->key,
                    $field->name(),
                    $filter->column,
                ));
            }

            return $filter->withThroughPath($filter->key);
        }

        return $filter;
    }

    /**
     * `$sort` resolved against `$fields`, the inventory of the type it orders: a sort
     * reaching through relations with its path resolved, any other sort unchanged.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when the path names no relation, crosses a to-many or a polymorphic relation, or its reached type declares no field the sort can order by
     */
    public function resolveSort(SortInterface $sort, iterable $fields): SortInterface
    {
        if (!$sort instanceof ReachesThrough || ($through = $sort->throughRelations()) === null || $sort->resolvedThrough() !== null) {
            return $sort;
        }

        return $sort->withResolvedThrough($this->relationPath($sort->key(), $through, $fields));
    }

    /**
     * Resolves a `->through()` path of API relation names against `$fields`, the
     * inventory of the type it starts from. Every segment must name a monomorphic
     * relation whose related type is registered.
     *
     * `$type` names the type the path starts from, the parent type of its first hop.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when a segment names no relation, a polymorphic one, or one whose related type is not registered
     */
    public function relationPath(string $key, string $path, iterable $fields, string $type = ''): RelationPath
    {
        $fields = self::listed($fields);
        $hops = [];
        $parent = $type;
        $storage = [];
        foreach (ThroughPath::split($path) as $segment) {
            $relation = null;
            foreach ($fields as $field) {
                if ($field instanceof RelationInterface && $field->name() === $segment) {
                    $relation = $field;

                    break;
                }
            }
            if ($relation === null) {
                throw new \LogicException(\sprintf('"%s" reaches through "%s", but "%s" is not a relation of the type it reaches; name each relation by its API name.', $key, $path, $segment));
            }
            if ($relation instanceof PolymorphicRelationInterface || \count($relation->relatedTypes()) !== 1) {
                throw new \LogicException(\sprintf('"%s" reaches through "%s", but "%s" is polymorphic; a path reaches one related type at each hop.', $key, $path, $segment));
            }

            $relatedType = $relation->relatedTypes()[0];
            $next = ($this->fieldsOf)($relatedType)
                ?? throw new \LogicException(\sprintf('"%s" reaches through "%s", but the type "%s" that "%s" relates to is not a registered resource.', $key, $path, $relatedType, $segment));
            $storage[] = $relation->column() ?? $relation->name();
            $hops[] = new RelationHop($relation, $parent, \implode('.', $storage));
            $parent = $relatedType;
            $fields = self::listed($next);
        }

        return new RelationPath($path, $hops, $fields);
    }

    /**
     * Resolves a {@see WhereMorph}'s relation against `$fields`: the polymorphic relation
     * stored under its name, and each declared type's id field.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when the type declares no relation under that name, or it is not polymorphic
     */
    public function morphTarget(WhereMorph $filter, iterable $fields): MorphTarget
    {
        $relation = self::relationStoredAs(self::listed($fields), $filter->relation);
        if (!$relation instanceof PolymorphicRelationInterface) {
            throw new \LogicException(\sprintf(
                $relation === null
                    ? 'WhereMorph filter "%s" names the relation "%s", which the type it filters does not declare.'
                    : 'WhereMorph filter "%s" names the relation "%s", which is not polymorphic. It compares a MorphTo or MorphToMany; use WhereThrough or WhereThroughIn for a relation over one type.',
                $filter->key,
                $filter->relation,
            ));
        }

        $types = $relation->relatedTypes();
        $ids = [];
        foreach ($types as $type) {
            $related = ($this->fieldsOf)($type);
            $ids[$type] = $related === null ? null : self::idField(self::listed($related));
        }

        return new MorphTarget($relation, $types, $ids);
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
     * The declared relations each leading segment of the dotted storage path `$path`
     * walks from `$fields`, the inventory of the type the path starts on. Each entry is
     * the relation the segment names, the one named after it among those stored under
     * it, or each relation stored under it when none is named so. The walk stops after the first segment that is not a relation, or whose
     * relation is polymorphic or relates to a type with no inventory, so a path ending
     * on a field has one entry fewer than it has segments.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<non-empty-list<RelationInterface>>
     */
    public function relationsAlong(string $path, iterable $fields): array
    {
        $walked = [];
        $fields = self::listed($fields);
        foreach (ThroughPath::split($path) as $segment) {
            $stored = self::relationsStoredAs($fields, $segment);
            if ($stored === []) {
                break;
            }

            $walked[] = $stored;
            $related = $stored[0]->relatedTypes();
            $next = !$stored[0] instanceof PolymorphicRelationInterface && \count($related) === 1 ? ($this->fieldsOf)($related[0]) : null;
            if ($next === null) {
                break;
            }
            $fields = self::listed($next);
        }

        return $walked;
    }

    /**
     * The relation stored under `$segment`: the one named `$segment` when several share
     * the storage member, else the first declared.
     *
     * @param iterable<FieldInterface> $fields
     */
    private static function relationStoredAs(iterable $fields, string $segment): ?RelationInterface
    {
        return self::relationsStoredAs($fields, $segment)[0] ?? null;
    }

    /**
     * The relations a segment names: the one named `$segment` among those stored under
     * it, or every relation stored under it when none is named so.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<RelationInterface>
     */
    private static function relationsStoredAs(iterable $fields, string $segment): array
    {
        $named = [];
        $others = [];
        foreach ($fields as $field) {
            if ($field instanceof RelationInterface && $field->column() === $segment) {
                if ($field->name() === $segment) {
                    $named[] = $field;
                } else {
                    $others[] = $field;
                }
            }
        }

        return $named !== [] ? [$named[0]] : $others;
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
