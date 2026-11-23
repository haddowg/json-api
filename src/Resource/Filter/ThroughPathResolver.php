<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
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
 * `pivot` segment names the pivot row of the relation before it, or of the relation the
 * filter or sort runs under when it leads the path; a path ending there reads a declared
 * pivot field, and one going on walks a relation the pivot row holds, which the data
 * layer's {@see PivotRowRelations} names. A {@see TraversesPath} filter's path names
 * relations by storage name, as described below.
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
     * @param \Closure(string): (iterable<FieldInterface>|null) $fieldsOf  a type's field inventory, or `null` when the type has none
     * @param PivotRowRelations|null                           $pivotRows the relations pivot rows hold, read from the data layer's storage; without it a path can't go on past a `pivot` segment
     */
    public function __construct(
        private readonly \Closure $fieldsOf,
        private readonly ?PivotRowRelations $pivotRows = null,
    ) {}

    /**
     * A resolver over the registered resources, reading each related type's inventory
     * from its {@see AbstractResource}, and the relations a pivot row holds from
     * `$pivotRows`.
     */
    public static function forResolver(SerializerResolverInterface $resolver, ?PivotRowRelations $pivotRows = null): self
    {
        return new self(static function (string $type) use ($resolver): ?array {
            if (!$resolver->hasSerializerFor($type)) {
                return null;
            }

            $serializer = $resolver->serializerFor($type);

            return $serializer instanceof AbstractResource ? $serializer->allFields() : null;
        }, $pivotRows);
    }

    /**
     * The relations pivot rows hold, as the data layer names them, if it does.
     */
    public function pivotRows(): ?PivotRowRelations
    {
        return $this->pivotRows;
    }

    /**
     * `$filters` with every path-traversing filter resolved against `$fields`, the
     * inventory of the type the filters run against. A group's children are resolved in
     * place; a filter family resolves its filters per request, so resolve what it
     * returns instead.
     *
     * `$under` is the relation the filters run under, as the type declaring it holds it, on
     * a relation's related and relationship endpoints and in its `relatedQuery` vocabulary;
     * a path starting with `pivot` reaches its pivot row. Its `path` is not read. `$type` is
     * the type the filters run against, the parent type of each path's first hop, which a
     * `pivot` after that hop asks {@see PivotRowRelations} about; when empty, it is the
     * related type of `$under`.
     *
     * @param list<FilterInterface>    $filters
     * @param iterable<FieldInterface> $fields
     *
     * @return list<FilterInterface>
     *
     * @throws \LogicException when a path ends on a polymorphic relation, its related type is not registered, or the filter cannot compare what the path ends on
     */
    public function resolveAll(array $filters, iterable $fields, ?RelationHop $under = null, string $type = ''): array
    {
        $fields = self::listed($fields);

        return \array_map(fn(FilterInterface $filter): FilterInterface => $this->resolve($filter, $fields, $under, $type), $filters);
    }

    /**
     * `$filter` resolved against `$fields`, the inventory of `$type`, the type it runs
     * against, under the relation `$under` when it runs under one. See {@see resolveAll()}.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when a path ends on a polymorphic relation, its related type is not registered, or the filter cannot compare what the path ends on
     */
    public function resolve(FilterInterface $filter, iterable $fields, ?RelationHop $under = null, string $type = ''): FilterInterface
    {
        $fields = self::listed($fields);

        return DateFilterValue::withDateOperands($this->resolvePath($filter, $fields, $under, $type), $fields);
    }

    /**
     * @param list<FieldInterface> $fields
     */
    private function resolvePath(FilterInterface $filter, array $fields, ?RelationHop $under, string $type): FilterInterface
    {
        if (($filter instanceof WhereIdIn || $filter instanceof WhereIdNotIn) && $filter->throughRelations() === null) {
            $filter = $this->relatedIds($filter, self::listed($fields));
        }

        if ($filter instanceof ReachesThrough && ($through = $filter->throughRelations()) !== null) {
            return $filter->resolvedThrough() !== null
                ? $filter
                : $filter->withResolvedThrough($this->relationPath($filter->key(), $through, $fields, self::startType($type, $under), $under));
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
            $children = $this->resolveAll($filter->children, $fields, $under, $type);

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
     * `$sort` resolved against `$fields`, the inventory of the type it orders, under the
     * relation `$under` when it runs under one: a sort reaching through relations with its
     * path resolved, any other sort unchanged. `$type` names the type it orders, as for
     * {@see resolveAll()}.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when the path names no relation, crosses a to-many or a polymorphic relation, or its reached type declares no field the sort can order by
     */
    public function resolveSort(SortInterface $sort, iterable $fields, ?RelationHop $under = null, string $type = ''): SortInterface
    {
        if (!$sort instanceof ReachesThrough || ($through = $sort->throughRelations()) === null || $sort->resolvedThrough() !== null) {
            return $sort;
        }

        return $sort->withResolvedThrough($this->relationPath($sort->key(), $through, $fields, self::startType($type, $under), $under));
    }

    /**
     * The type a path starts from: `$type`, or the related type of the relation the path
     * runs under, whose inventory the path is resolved against.
     */
    private static function startType(string $type, ?RelationHop $under): string
    {
        if ($type !== '' || $under === null) {
            return $type;
        }

        $related = $under->relation->relatedTypes();

        return \count($related) === 1 ? $related[0] : '';
    }

    /**
     * Resolves a `->through()` path of API relation names against `$fields`, the
     * inventory of the type it starts from. Every segment must name a monomorphic
     * relation whose related type is registered, or be `pivot`.
     *
     * `$type` names the type the path starts from, the parent type of its first hop.
     * `$under` is the relation the filter or sort runs under, whose pivot row a leading
     * `pivot` reaches.
     *
     * A `pivot` segment reaches the pivot rows of the relation before it, which must have
     * them ({@see RelationPath::hasPivotRow()}); that relation may be polymorphic, since
     * the path goes on from its pivot rows rather than its members. The segment after a
     * `pivot` names a relation the pivot row holds, through {@see PivotRowRelations}.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @throws \LogicException when a segment names no relation, a polymorphic one, or one whose related type is not registered, or a `pivot` segment follows no relation with pivot rows
     */
    public function relationPath(string $key, string $path, iterable $fields, string $type = '', ?RelationHop $under = null): RelationPath
    {
        $fields = self::listed($fields);
        $hops = [];
        $parent = $type;
        $storage = [];
        $pivotOf = null;
        $pivotParent = '';
        $segments = ThroughPath::split($path);
        foreach ($segments as $index => $segment) {
            if ($segment === RelationPath::PIVOT) {
                foreach ($fields as $field) {
                    if ($field instanceof RelationInterface && $field->name() === RelationPath::PIVOT) {
                        throw new \LogicException(\sprintf('"%s" reaches through "%s", but the type there declares a relation named "pivot", which the segment would hide; a `pivot` segment always names a pivot row, so give the relation another name.', $key, $path));
                    }
                }

                $previous = $index === 0 ? null : \array_pop($hops);
                if ($index > 0 && ($previous === null || $pivotOf !== null || $previous->pivot)) {
                    throw new \LogicException(\sprintf('"%s" reaches through "%s", but a `pivot` segment follows another pivot row, which has no pivot rows of its own.', $key, $path));
                }
                $relation = $previous === null ? $under?->relation : $previous->relation;
                if ($relation === null) {
                    throw new \LogicException(\sprintf('"%s" reaches through "%s", which starts at `pivot`, the pivot row of the relation it runs under, but it runs under none; declare it among the filters or sorts of a relation with pivot rows.', $key, $path));
                }
                if (!RelationPath::hasPivotRow($relation)) {
                    throw new \LogicException(\sprintf(
                        '"%s" reaches through "%s", but `pivot` follows the relation "%s", which has no pivot rows; a `pivot` segment follows a BelongsToMany over an association class or with stored pivot fields, or a MorphToMany with stored pivot fields.',
                        $key,
                        $path,
                        $relation->name(),
                    ));
                }

                $pivotParent = $previous === null ? ($under === null ? '' : $under->parentType) : $previous->parentType;
                $storage[] = RelationPath::PIVOT;
                $hops[] = new RelationHop($relation, $pivotParent, \implode('.', $storage), true);
                $pivotOf = $relation;
                $parent = '';
                $fields = $relation->pivotFields();

                continue;
            }

            $nextIsPivot = ($segments[$index + 1] ?? null) === RelationPath::PIVOT;
            if ($pivotOf !== null) {
                $relation = $this->pivotRows?->pivotRowRelation($pivotOf, $pivotParent, $segment)
                    ?? throw new \LogicException(\sprintf('"%s" reaches through "%s", but "%s" is not a relation the pivot rows of "%s" hold; after `pivot`, name a relation of the pivot row, or end the path there to read a pivot field.', $key, $path, $segment, $pivotOf->name()));
                $pivotOf = null;
            } else {
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
            }
            $polymorphic = $relation instanceof PolymorphicRelationInterface || \count($relation->relatedTypes()) !== 1;
            if ($polymorphic && !$nextIsPivot) {
                throw new \LogicException(\sprintf('"%s" reaches through "%s", but "%s" is polymorphic; a path reaches one related type at each hop.', $key, $path, $segment));
            }

            $storage[] = $relation->column() ?? $relation->name();
            $hops[] = new RelationHop($relation, $parent, \implode('.', $storage));
            if ($polymorphic) {
                $fields = [];

                continue;
            }

            $relatedType = $relation->relatedTypes()[0];
            $next = ($this->fieldsOf)($relatedType);
            if ($next === null) {
                if ($nextIsPivot) {
                    $fields = [];

                    continue;
                }

                throw new \LogicException(\sprintf('"%s" reaches through "%s", but the type "%s" that "%s" relates to is not a registered resource.', $key, $path, $relatedType, $segment));
            }
            $parent = $relatedType;
            $fields = self::listed($next);
        }

        $pivotRowRelation = null;
        if ($pivotOf !== null && $this->pivotRows !== null) {
            $rows = $this->pivotRows;
            $owner = $pivotOf;
            $ownerParent = $pivotParent;
            $pivotRowRelation = static fn(string $member): ?RelationInterface => $rows->pivotRowRelation($owner, $ownerParent, $member);
        }

        return new RelationPath($path, $hops, $fields, null, $pivotRowRelation);
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
     * The declared relations each segment after the leading `pivot` of a deprecated
     * traversal's storage path walks, from the pivot row of `$under`, the relation the
     * filter runs under: the relation {@see PivotRowRelations} names for the first member,
     * then each further segment on the type it reaches, as {@see relationsAlong()} walks
     * them. The walk is empty for a path that doesn't start with `pivot`, and stops where
     * no relation names a segment, so `pivot.position` walks nothing.
     *
     * A member the data layer can't name one type for, an entity no registered type serves
     * or several do, ends the walk there, since no type's rules can apply to it. The adapters
     * refuse a declared filter reaching one at warm-up in either form, so only one a filter
     * family resolves per request can still end its walk here.
     *
     * @return list<non-empty-list<RelationInterface>>
     */
    public function pivotRelationsAlong(string $path, RelationHop $under): array
    {
        $segments = ThroughPath::split($path);
        if (($segments[0] ?? null) !== RelationPath::PIVOT || \count($segments) < 2 || $this->pivotRows === null || !$under->relation instanceof PivotRelationInterface) {
            return [];
        }

        try {
            $member = $this->pivotRows->pivotRowRelation($under->relation, $under->parentType, $segments[1]);
        } catch (\LogicException) {
            return [];
        }
        if ($member === null) {
            return [];
        }

        $related = $member->relatedTypes();
        $fields = \count($related) === 1 ? ($this->fieldsOf)($related[0]) : null;
        $rest = \array_slice($segments, 2);

        return [[$member], ...($fields === null || $rest === [] ? [] : $this->relationsAlong(\implode('.', $rest), $fields))];
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
