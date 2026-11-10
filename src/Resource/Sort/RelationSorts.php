<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DefaultSortedRelationInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\StoresIdentifiersInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * The sorts a to-many relation's relationship and related endpoints accept, and the
 * order its members are read in when a request names none. Every data layer reads both
 * here, so a relation means the same thing on every read path: document linkage, the
 * two endpoints, include batching and Relationship Queries windows.
 */
final class RelationSorts
{
    /**
     * The sorts the relation derives from its pivot fields: one {@see SortByField} per
     * stored field, keyed by the field name and columned by its stored column. A
     * computed field has no column to sort by. Empty for a relation without pivot
     * fields.
     *
     * @return list<SortInterface>
     */
    public static function pivotSorts(RelationInterface $relation): array
    {
        return $relation instanceof PivotRelationInterface ? self::pivotSortsOf($relation->storedPivotFields()) : [];
    }

    /**
     * The sorts `$pivotFields` derive, as {@see pivotSorts()} describes.
     *
     * @param list<FieldInterface> $pivotFields
     *
     * @return list<SortInterface>
     */
    public static function pivotSortsOf(array $pivotFields): array
    {
        $sorts = [];
        foreach ($pivotFields as $field) {
            $column = $field->column();
            if ($column !== null) {
                $sorts[] = SortByField::make($field->name(), $column);
            }
        }

        return $sorts;
    }

    /**
     * The relation's sort vocabulary: the related type's sorts merged with the
     * relation's {@see RelationInterface::sorts()} and its pivot sorts, a later key
     * replacing an earlier one. A polymorphic relation whose members are identified by
     * stored pairs sorts its link rows by its pivot sorts alone. Empty for a to-one and
     * for any other polymorphic relation, whose members share no related type.
     *
     * @return list<SortInterface>
     */
    public static function vocabulary(RelationInterface $relation, SerializerResolverInterface $resolver): array
    {
        if (!$relation->isToMany()) {
            return [];
        }

        if (self::isPolymorphic($relation)) {
            return $relation instanceof StoresIdentifiersInterface && $relation->storesIdentifiers() ? self::pivotSorts($relation) : [];
        }

        $related = self::relatedResource($relation, $resolver);

        return SortMatcher::merge($related?->allSorts() ?? [], $relation->sorts(), self::pivotSorts($relation));
    }

    /**
     * The order to read the relation's members in when the request names no sort: the
     * relation's own {@see DefaultSortedRelationInterface::defaultSort()}, else the
     * related type's {@see AbstractResource::defaultSort()}, else `[]`, storage order.
     * A polymorphic relation has no related type to fall back to.
     *
     * @return list<SortDirective>
     */
    public static function defaultFor(RelationInterface $relation, SerializerResolverInterface $resolver): array
    {
        if (!$relation->isToMany()) {
            return [];
        }

        if ($relation instanceof DefaultSortedRelationInterface && $relation->defaultSort() !== []) {
            return $relation->defaultSort();
        }

        if (self::isPolymorphic($relation)) {
            return [];
        }

        return self::relatedResource($relation, $resolver)?->defaultSort() ?? [];
    }

    private static function isPolymorphic(RelationInterface $relation): bool
    {
        return $relation instanceof PolymorphicRelationInterface || \count($relation->relatedTypes()) !== 1;
    }

    private static function relatedResource(RelationInterface $relation, SerializerResolverInterface $resolver): ?AbstractResource
    {
        $type = $relation->relatedTypes()[0] ?? null;
        if ($type === null || !$resolver->hasSerializerFor($type)) {
            return null;
        }

        $serializer = $resolver->serializerFor($type);

        return $serializer instanceof AbstractResource ? $serializer : null;
    }
}
