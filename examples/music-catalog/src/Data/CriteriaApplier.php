<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Data;

use haddowg\JsonApi\Examples\MusicCatalog\Filter\WithinRadius;
use haddowg\JsonApi\Examples\MusicCatalog\Sort\TrackCountSort;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;

/**
 * Applies the request's `filter[…]` and `sort` parameters to an in-memory
 * `list<object>` by composing the library's reference
 * {@see ArrayFilterHandler} + {@see ArraySortHandler}.
 *
 * It owns the two custom-vocabulary arms the reference handlers cannot execute:
 * a {@see WithinRadius} filter arm and a computed {@see TrackCountSort} pre-arm —
 * the metadata/handler split a real adapter (Doctrine, …) implements the same way.
 * Built-in filters/sorts delegate to the reference handlers; an unrecognised VO
 * throws {@see \haddowg\JsonApi\Resource\Filter\UnsupportedFilter} /
 * {@see UnsupportedSort} (a 500, a server-config error).
 */
final class CriteriaApplier
{
    public function __construct(
        private readonly ArrayFilterHandler $filters = new ArrayFilterHandler(),
        private readonly ArraySortHandler $sorts = new ArraySortHandler(),
    ) {}

    /**
     * Filter, then sort. Sort is applied last so it operates on the filtered set.
     *
     * `$foldDefaults` folds the resource's filter defaults into the requested set
     * (a primary collection wants this — an absent `filter[explicit]` still
     * defaults to `false`). A related sub-collection passes `false`: only the
     * filters/sorts the client actually requested apply, so `GET /albums/1/tracks`
     * is the album's full track set, not the primary-collection default view.
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    public function apply(
        array $rows,
        AbstractResource $resource,
        JsonApiRequestInterface $request,
        bool $foldDefaults = true,
    ): array {
        $rows = $this->applyFilters($rows, $resource, $request, $foldDefaults);

        return $this->applySorts($rows, $resource, $request);
    }

    /**
     * @param list<object> $rows
     *
     * @return list<object>
     */
    private function applyFilters(array $rows, AbstractResource $resource, JsonApiRequestInterface $request, bool $foldDefaults = true): array
    {
        /** @var array<string, FilterInterface> $declared */
        $declared = [];
        foreach ($resource->filters() as $filter) {
            // First declared wins for a shared key (the FilterDefaults rule).
            $declared[$filter->key()] ??= $filter;
        }

        $requested = $foldDefaults
            ? FilterDefaults::apply($request->getFiltering(), $resource->filters())
            : $request->getFiltering();

        foreach ($requested as $key => $value) {
            $filter = $declared[$key] ?? null;
            if ($filter === null) {
                continue;
            }

            $rows = $filter instanceof WithinRadius
                ? $this->withinRadius($rows, $filter, $value)
                : $this->delegateFilter($filter, $rows, $value);
        }

        return $rows;
    }

    /**
     * @param list<object> $rows
     *
     * @return list<object>
     */
    private function delegateFilter(FilterInterface $filter, array $rows, mixed $value): array
    {
        /** @var list<object> $result */
        $result = $this->filters->apply($filter, $rows, $value);

        return $result;
    }

    /**
     * The worked custom-filter arm: keeps rows whose `{latColumn, lngColumn}`
     * fall within `value.km` of `value.{lat, lng}` (a flat-earth approximation —
     * a real adapter would push a spatial predicate down to its store).
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    private function withinRadius(array $rows, WithinRadius $filter, mixed $value): array
    {
        if (!\is_array($value)) {
            return $rows;
        }

        $centreLat = self::toFloat($value['lat'] ?? null);
        $centreLng = self::toFloat($value['lng'] ?? null);
        $radiusKm = self::toFloat($value['km'] ?? null);

        return \array_values(\array_filter($rows, static function (object $row) use ($filter, $centreLat, $centreLng, $radiusKm): bool {
            $lat = Accessor::get($row, $filter->latColumn);
            $lng = Accessor::get($row, $filter->lngColumn);
            if (!\is_numeric($lat) || !\is_numeric($lng)) {
                return false;
            }

            // ~111 km per degree — adequate for a worked in-memory example.
            $dLat = ((float) $lat - $centreLat) * 111.0;
            $dLng = ((float) $lng - $centreLng) * 111.0;

            return \sqrt(($dLat * $dLat) + ($dLng * $dLng)) <= $radiusKm;
        }));
    }

    private static function toFloat(mixed $value): float
    {
        return \is_numeric($value) ? (float) $value : 0.0;
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<object> $rows
     *
     * @return list<object>
     */
    private function applySorts(array $rows, AbstractResource $resource, JsonApiRequestInterface $request): array
    {
        $requested = $request->getSorting();

        // No `?sort`: fall back to the resource's default order (if any). An
        // explicit `?sort` overrides the default entirely — the default is never
        // appended to a requested sort.
        if ($requested === []) {
            $directives = $resource->defaultSort();

            return $directives === [] ? $rows : $this->runDirectives($rows, $directives);
        }

        /** @var array<string, SortInterface> $allSorts */
        $allSorts = [];
        foreach ($resource->allSorts() as $sort) {
            $allSorts[$sort->key()] = $sort;
        }

        $directives = [];
        foreach ($requested as $entry) {
            $descending = \str_starts_with($entry, '-');
            $key = $descending ? \substr($entry, 1) : $entry;

            $sort = $allSorts[$key] ?? null;
            if ($sort === null) {
                continue;
            }

            $directives[] = new SortDirective($sort, $descending);
        }

        if ($directives === []) {
            return $rows;
        }

        return $this->runDirectives($rows, $directives);
    }

    /**
     * Executes an ordered list of {@see SortDirective}s — from a requested `?sort`
     * or from a resource's {@see AbstractResource::defaultSort()} — over the rows.
     *
     * @param list<object>         $rows
     * @param list<SortDirective>  $directives
     *
     * @return list<object>
     */
    private function runDirectives(array $rows, array $directives): array
    {
        // The reference ArraySortHandler only understands SortByField. A computed
        // sort (TrackCountSort) is executed by a pre-arm here so the handler never
        // sees it; mixing a computed sort with field sorts is out of scope for this
        // worked example, so the first computed directive owns the ordering.
        foreach ($directives as $directive) {
            if ($directive->sort instanceof TrackCountSort) {
                return $this->sortByTrackCount($rows, $directive->sort, $directive->descending);
            }
        }

        /** @var list<object> $sorted */
        $sorted = $this->sorts->apply($directives, $rows);

        return $sorted;
    }

    /**
     * The worked computed-sort pre-arm.
     *
     * @param list<object> $rows
     *
     * @return list<object>
     */
    private function sortByTrackCount(array $rows, TrackCountSort $sort, bool $descending): array
    {
        \usort($rows, static function (object $a, object $b) use ($sort, $descending): int {
            $cmp = self::toInt(Accessor::get($a, $sort->column)) <=> self::toInt(Accessor::get($b, $sort->column));

            return $descending ? -$cmp : $cmp;
        });

        return $rows;
    }
}
