<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\LinkRowFields;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\FilterDirective;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\TargetsColumn;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

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
 *
 * Which rows count, and in what order, is fixed here so every data layer agrees. A
 * relation declared with `identifiedByRows()` carries its {@see LinkRowFields}, and a row
 * of the parent counts when its stored type names a declared type ({@see typeValues()})
 * and it has an id. A type the host narrows with {@see withAdmitted()}, such as one a
 * scope hides rows of, counts only the rows whose id it admits ({@see admittedIds()}).
 * Both apply before the filters, the sorts and the page. After the sorts, ties break by
 * {@see tiebreak()}, ascending. {@see member()} turns a row the query returns into its
 * member. {@see InMemoryLinkRows} applies the same rules.
 */
final readonly class LinkRowQuery
{
    /**
     * The prefix a filter's column carries to target a pivot field of the link row.
     */
    public const string PIVOT_PREFIX = 'pivot.';

    /**
     * @param list<SortDirective>   $sorts     the order, most significant first; `[]` orders by the tiebreak alone, or keeps the closure's order
     * @param list<FilterDirective> $filters   the filters every returned row must pass
     * @param WindowInterface|null  $window    the page to return, or `null` for every row
     * @param bool                  $countable whether a page also counts the rows the filters select
     * @param LinkRowFields|null    $fields    the relation's declared link-row fields, `null` for one declared with `identifiedBy()`
     * @param array<string, list<string|int>> $admitted the storage keys each narrowed JSON:API type admits; a type not listed admits every row
     */
    public function __construct(
        public array $sorts = [],
        public array $filters = [],
        public ?WindowInterface $window = null,
        public bool $countable = false,
        public ?LinkRowFields $fields = null,
        public array $admitted = [],
    ) {}

    /**
     * The same query with the rows of `$type` narrowed to the storage keys `$ids`, every
     * other row of that type left out before the filters and the page. Hosts narrow a
     * type whose rows a scope may hide, after reading which of the parent's ids it
     * admits. An empty `$ids` leaves out every row of the type.
     *
     * @param iterable<string|int> $ids
     */
    public function withAdmitted(string $type, iterable $ids): self
    {
        $admitted = $this->admitted;
        $admitted[$type] = [];
        foreach ($ids as $id) {
            $admitted[$type][] = $id;
        }

        return new self($this->sorts, $this->filters, $this->window, $this->countable, $this->fields, $admitted);
    }

    /**
     * The same query with each of `$guarded` narrowed to the members its provider admits,
     * the one way hosts narrow, so every data layer does it alike. It reads `$parent`'s
     * keys of the guarded types through `$keys`, asks `$admit` which of each type's keys
     * its provider serves, and narrows the type to those ({@see withAdmitted()}). A guarded
     * type the parent links no member of is narrowed to none without asking. Run it before
     * the query reaches the data layer, so the narrowing applies ahead of the filters and
     * the page.
     *
     * @param list<string>                                                    $guarded the declared types a scope may hide rows of
     * @param \Closure(string, non-empty-list<string|int>): iterable<string|int> $admit   receives a type and the parent's keys of it, returns the keys it admits
     */
    public function narrowed(mixed $parent, MorphToMany $relation, LinkRowKeysInterface $keys, array $guarded, \Closure $admit, JsonApiRequestInterface $request): self
    {
        if ($guarded === []) {
            return $this;
        }

        $stored = $keys->linkRowKeys($parent, $relation, $guarded, $request);
        $query = $this;
        foreach ($guarded as $type) {
            $ids = $stored[$type] ?? [];
            $query = $query->withAdmitted($type, $ids === [] ? [] : $admit($type, $ids));
        }

        return $query;
    }

    /**
     * Whether `$member` survives the narrowing: its type is not narrowed, or its id is one
     * the type admits.
     */
    public function admits(IdentifiedMember $member): bool
    {
        if (!isset($this->admitted[$member->type])) {
            return true;
        }

        $id = (string) $member->id;
        foreach ($this->admitted[$member->type] as $admitted) {
            if ((string) $admitted === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The member a row the query returned links to, or `null` when the row doesn't count:
     * its stored type names no declared type, it has no id, or its type is narrowed and
     * the id isn't admitted.
     *
     * @throws \LogicException when the relation was not declared with identifiedByRows()
     */
    public function member(mixed $row): ?IdentifiedMember
    {
        $member = $this->declared()->member($row);

        return $member !== null && $this->admits($member) ? $member : null;
    }

    /**
     * The stored type values whose rows count whatever their id: those of every declared
     * type the query doesn't narrow. A data layer selects a row when its type field holds
     * one of these, or matches {@see admittedIds()}.
     *
     * @return list<string>
     *
     * @throws \LogicException when the relation was not declared with identifiedByRows()
     */
    public function typeValues(): array
    {
        $fields = $this->declared();
        $values = [];
        foreach ($fields->types as $type) {
            if (!isset($this->admitted[$type])) {
                \array_push($values, ...$fields->storedValues($type));
            }
        }

        return $values;
    }

    /**
     * The storage keys counted for each stored type value of a narrowed type, keyed by
     * that value. A data layer selects a row when its type field holds the key and its id
     * field one of the ids. A narrowed type that admits no id is absent.
     *
     * @return array<string, non-empty-list<string|int>>
     *
     * @throws \LogicException when the relation was not declared with identifiedByRows()
     */
    public function admittedIds(): array
    {
        $fields = $this->declared();
        $ids = [];
        foreach ($this->admitted as $type => $admitted) {
            if ($admitted === []) {
                continue;
            }
            foreach ($fields->storedValues($type) as $value) {
                $ids[$value] = $admitted;
            }
        }

        return $ids;
    }

    /**
     * The row fields that order rows the sorts leave tied, each ascending, applied after
     * every sort: the stored type, then the id. `[]` for a relation declared with
     * `identifiedBy()`, whose ties keep the closure's order.
     *
     * @return list<string>
     */
    public function tiebreak(): array
    {
        return $this->fields?->tiebreak() ?? [];
    }

    /**
     * The query a request makes of `$relation`'s link rows. `$sort` and `$filter` are the
     * request's values for the relation: an endpoint's `sort` and `filter`, a Relationship
     * Queries `relatedQuery[<path>]` entry, or nothing when the relation renders as
     * linkage in another resource's document. Without a requested sort the relation's
     * own default applies: its declared default, else its position order.
     *
     * @param list<string>         $sort   the requested sort fields, a leading `-` marking descending
     * @param array<string, mixed> $filter the requested filter values, keyed by filter key
     *
     * @throws \haddowg\JsonApi\Exception\SortingUnsupported    when a sort is requested and the relation declares no pivot field to sort by
     * @throws \haddowg\JsonApi\Exception\SortParamUnrecognized when a requested sort key is not one of its pivot sorts
     * @throws \haddowg\JsonApi\Exception\FilterParamUnrecognized when a requested filter key is not one of its pivot filters
     * @throws \LogicException                                  when the relation is declared with neither identifiedBy() nor identifiedByRows()
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
            throw new \LogicException(\sprintf('MorphToMany relation "%s" was not declared with identifiedBy() or identifiedByRows(), so it has no link rows to query.', $relation->name()));
        }

        $sorts = SortMatcher::directives($sort, self::sorts($relation), $request);

        return new self(
            $sorts === [] ? RelationSorts::ownDefault($relation) : $sorts,
            FilterMatcher::directives($filter, self::filters($relation), $request),
            $window,
            $countable,
            $relation->linkRowFields(),
        );
    }

    /**
     * Why `$relation`'s link rows are served in memory, for a host to report at warm-up,
     * or `null` when they aren't or nothing would be. A relation declared with
     * `identifiedBy()` gives no data layer its rows' fields, so every request reads all
     * of a parent's rows through the closure and filters, sorts and pages them in memory;
     * that is worth saying once it declares pivot fields, filters, sorts, a default
     * order or a count, or a paginator applies: its own, else `$fallback`, the server's.
     */
    public static function inMemoryNotice(MorphToMany $relation, ?PaginatorInterface $fallback = null): ?string
    {
        if (!$relation->storesIdentifiers() || $relation->linkRowFields() !== null) {
            return null;
        }

        $queried = $relation->pivotFields() !== []
            || $relation->allFilters() !== []
            || $relation->sorts() !== []
            || RelationSorts::ownDefault($relation) !== []
            || $relation->pagination($fallback) !== null
            || $relation->isCountable();
        if (!$queried) {
            return null;
        }

        return \sprintf(
            'MorphToMany relation "%s" reads its link rows through an identifiedBy() closure, so its linkage and its relationship and related endpoints load every row of a parent and filter, sort and page them in memory. Declare identifiedByRows() with the rows\' owner, type and id fields to let the data layer query them.',
            $relation->name(),
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

    private function declared(): LinkRowFields
    {
        return $this->fields ?? throw new \LogicException('This link-row query has no declared row fields: its relation was declared with identifiedBy(), whose rows only the closure can read.');
    }

    /**
     * `$column` with its `pivot.` prefix removed: the link row's own column.
     */
    public static function rowColumn(string $column): string
    {
        return \str_starts_with($column, self::PIVOT_PREFIX) ? \substr($column, \strlen(self::PIVOT_PREFIX)) : $column;
    }
}
