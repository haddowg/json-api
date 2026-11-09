<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\FilterDirective;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\TargetsColumn;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;

/**
 * What to read of an identified {@see MorphToMany}'s link rows: the filters that select
 * them, the order they come in and the page of them to return. A data layer executes
 * it through a {@see LinkRowQueryInterface}; {@see InMemoryLinkRows} is the reference.
 *
 * Every key resolves against the relation's own vocabulary, which is its link rows'
 * pivot fields only, since its members span types and share nothing else: the sorts
 * its stored pivot fields derive ({@see sorts()}) and the filters it declares with
 * `withFilters()` on a `pivot.`-prefixed column ({@see filters()}). A sort's column and
 * a filter's column with the prefix stripped name a column of the link row.
 */
final readonly class LinkRowQuery
{
    /**
     * The prefix a filter's column carries to target a pivot field of the link row.
     */
    public const string PIVOT_PREFIX = 'pivot.';

    /**
     * @param list<SortDirective>   $sorts     the order, most significant first; `[]` keeps the identifiedBy() order
     * @param list<FilterDirective> $filters   the filters every returned row must pass
     * @param WindowInterface|null  $window    the page to return, or `null` for every row
     * @param bool                  $countable whether a page also counts the rows the filters select
     */
    public function __construct(
        public array $sorts = [],
        public array $filters = [],
        public ?WindowInterface $window = null,
        public bool $countable = false,
    ) {}

    /**
     * The query a request makes of `$relation`'s link rows. `$sort` and `$filter` are the
     * request's values for the relation: an endpoint's `sort` and `filter`, a Relationship
     * Queries `relatedQuery[<path>]` entry, or nothing when the relation renders as
     * linkage in another resource's document. Without a requested sort the relation's
     * own default applies.
     *
     * @param list<string>         $sort   the requested sort fields, a leading `-` marking descending
     * @param array<string, mixed> $filter the requested filter values, keyed by filter key
     *
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported    when a sort is requested and the relation declares no pivot field to sort by
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized when a requested sort key is not one of its pivot sorts
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not one of its pivot filters
     * @throws \LogicException                                  when the relation is not declared with identifiedBy()
     */
    public static function resolve(
        MorphToMany $relation,
        array $sort = [],
        array $filter = [],
        ?JsonApiRequestInterface $request = null,
        ?WindowInterface $window = null,
        bool $countable = false,
    ): self {
        if (!$relation->storesIdentifiers()) {
            throw new \LogicException(\sprintf('MorphToMany relation "%s" was not declared with identifiedBy(), so it has no link rows to query.', $relation->name()));
        }

        $sorts = SortMatcher::directives($sort, self::sorts($relation), $request);

        return new self(
            $sorts === [] ? $relation->defaultSort() : $sorts,
            FilterMatcher::directives($filter, self::filters($relation), $request),
            $window,
            $countable,
        );
    }

    /**
     * The sorts an identified `$relation` accepts: one per stored pivot field.
     *
     * @return list<SortInterface>
     */
    public static function sorts(MorphToMany $relation): array
    {
        return $relation->storesIdentifiers() ? RelationSorts::pivotSorts($relation) : [];
    }

    /**
     * The filters an identified `$relation` accepts: those it declares with
     * `withFilters()` whose column is `pivot.`-prefixed. A filter comparing anything
     * else has no column on the link row to compare.
     *
     * @return list<FilterInterface>
     */
    public static function filters(MorphToMany $relation): array
    {
        if (!$relation->storesIdentifiers()) {
            return [];
        }

        return \array_values(\array_filter(
            $relation->allFilters(),
            static fn(FilterInterface $filter): bool => $filter instanceof TargetsColumn && \str_starts_with($filter->targetColumn(), self::PIVOT_PREFIX),
        ));
    }

    /**
     * `$column` with its `pivot.` prefix removed: the link row's own column.
     */
    public static function rowColumn(string $column): string
    {
        return \str_starts_with($column, self::PIVOT_PREFIX) ? \substr($column, \strlen(self::PIVOT_PREFIX)) : $column;
    }
}
