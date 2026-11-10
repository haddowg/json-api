<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\LinkRows\Internal\LinkRowCarrier;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * The reference {@see LinkRowQueryInterface}: it reads the relation's members, each with
 * its link row ({@see MorphToMany::identifiedMembers()}), and applies the query in memory
 * under the rules {@see LinkRowQuery} sets: the narrowing first, then the filters and
 * sorts through the reference {@see ArrayFilterHandler} and {@see ArraySortHandler}, a
 * column reading the row's column with any `pivot.` prefix stripped, ties broken by the
 * query's tiebreak, or kept in the closure's order when it has none, then the page. Core
 * orders an identified relation's linkage by its default sort through it, a host whose
 * rows are already loaded can use it as is, and it is the witness a data layer's own
 * query is checked against. It is not a production query layer over large link tables.
 */
final readonly class InMemoryLinkRows implements LinkRowQueryInterface
{
    /**
     * @param FilterHandlerInterface<mixed> $filters
     * @param SortHandlerInterface<mixed>   $sorts
     */
    public function __construct(
        private FilterHandlerInterface $filters = new ArrayFilterHandler(),
        private SortHandlerInterface $sorts = new ArraySortHandler(),
    ) {}

    public function queryLinkRows(mixed $parent, MorphToMany $relation, LinkRowQuery $query, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->apply($relation->identifiedMembers($parent, $request), $query);
    }

    /**
     * `$members` filtered, sorted and paged per `$query`.
     *
     * @param list<IdentifiedMember> $members
     *
     * @return CollectionResult<IdentifiedMember>
     */
    public function apply(array $members, LinkRowQuery $query): CollectionResult
    {
        $members = \array_values(\array_filter($members, $query->admits(...)));
        $tiebreak = $query->tiebreak();
        if ($tiebreak !== []) {
            \usort($members, static function (IdentifiedMember $a, IdentifiedMember $b) use ($tiebreak): int {
                foreach ($tiebreak as $field) {
                    $order = self::compare(Accessor::get($a->row, $field), Accessor::get($b->row, $field));
                    if ($order !== 0) {
                        return $order;
                    }
                }

                return 0;
            });
        }

        // Sorting is stable, so rows the sorts leave tied keep the tiebreak order.
        $rows = \array_map(static fn(IdentifiedMember $member): LinkRowCarrier => new LinkRowCarrier($member), $members);
        foreach ($query->filters as $directive) {
            $rows = $this->rows($this->filters->apply($directive->filter, $rows, $directive->value));
        }
        if ($query->sorts !== []) {
            $rows = $this->rows($this->sorts->apply($query->sorts, $rows));
        }

        $selected = \array_map(static fn(LinkRowCarrier $row): IdentifiedMember => $row->member, $rows);
        $slice = static fn(int $offset, int $limit): array => \array_slice($selected, $offset, $limit);

        return (new WindowExecutor())->run(
            $query->window,
            $query->countable,
            all: static fn(): array => $selected,
            count: static fn(): int => \count($selected),
            page: $slice,
            probe: $slice,
        );
    }

    /**
     * Two values of one row field in the order a database orders its column: integers by
     * value, anything else by its string bytes.
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if (\is_int($a) && \is_int($b)) {
            return $a <=> $b;
        }

        $a = $a instanceof \BackedEnum ? $a->value : $a;
        $b = $b instanceof \BackedEnum ? $b->value : $b;

        return \strcmp(\is_scalar($a) ? (string) $a : '', \is_scalar($b) ? (string) $b : '');
    }

    /**
     * @return list<LinkRowCarrier>
     */
    private function rows(mixed $rows): array
    {
        return \is_array($rows) ? \array_values(\array_filter($rows, static fn(mixed $row): bool => $row instanceof LinkRowCarrier)) : [];
    }
}
