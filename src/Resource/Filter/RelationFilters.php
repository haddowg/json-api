<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Field\DropsFilterDefaultsInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The filters a relation's reads apply from its related type. A relation declaring
 * `withoutFilterDefaults()` keeps every related filter but drops the defaults it names,
 * so a client can still send those keys. Every data layer reads the related filters
 * here, so a relation drops the same defaults on every read path: document linkage,
 * `?include`, `?withCount`, its related and relationship endpoints and Relationship
 * Queries windows.
 */
final class RelationFilters
{
    /**
     * `$relatedFilters`, a related type's built filters, as `$relation` applies them:
     * in the same order, each filter whose default the relation drops replaced by its
     * {@see HasRemovableDefault::withoutDefault()} copy. Pass each related type's
     * filters separately for a polymorphic relation.
     *
     * @param list<FilterInterface> $relatedFilters
     *
     * @return list<FilterInterface>
     *
     * @throws \LogicException when the relation drops the default of a filter that cannot drop it
     */
    public static function relatedFilters(RelationInterface $relation, array $relatedFilters): array
    {
        if (!$relation instanceof DropsFilterDefaultsInterface || (!$relation->dropsAllFilterDefaults() && $relation->droppedFilterDefaults() === [])) {
            return $relatedFilters;
        }

        return \array_map(
            static fn(FilterInterface $filter): FilterInterface => self::dropsDefaultOf($relation, $filter)
                ? self::withoutDefault($relation, $filter)
                : $filter,
            $relatedFilters,
        );
    }

    /**
     * The relation's filter vocabulary: {@see relatedFilters()} merged with the
     * relation's own {@see RelationInterface::allFilters()}, a relation filter
     * replacing a related one with the same key. A default the relation declares on
     * its own filter applies as declared.
     *
     * @param list<FilterInterface> $relatedFilters
     *
     * @return list<FilterInterface>
     *
     * @throws \LogicException when the relation drops the default of a filter that cannot drop it
     */
    public static function vocabulary(RelationInterface $relation, array $relatedFilters): array
    {
        return FilterMatcher::merge(self::relatedFilters($relation, $relatedFilters), $relation->allFilters());
    }

    /**
     * Whether `$relation` drops the related type's default for the filter `$key`.
     */
    public static function dropsDefault(RelationInterface $relation, string $key): bool
    {
        return $relation instanceof DropsFilterDefaultsInterface
            && ($relation->dropsAllFilterDefaults() || \in_array($key, $relation->droppedFilterDefaults(), true));
    }

    private static function dropsDefaultOf(RelationInterface $relation, FilterInterface $filter): bool
    {
        return $filter instanceof HasDefaultValue && $filter->hasDefault() && self::dropsDefault($relation, $filter->key());
    }

    private static function withoutDefault(RelationInterface $relation, FilterInterface $filter): FilterInterface
    {
        if (!$filter instanceof HasRemovableDefault) {
            throw new \LogicException(\sprintf(
                'Relation "%s" drops the default of filter "%s", but %s cannot drop its default. Implement %s on it.',
                $relation->name(),
                $filter->key(),
                $filter::class,
                HasRemovableDefault::class,
            ));
        }

        return $filter->withoutDefault();
    }
}
