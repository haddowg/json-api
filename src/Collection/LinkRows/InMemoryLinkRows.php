<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Collection\LinkRows;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Collection\LinkRows\Internal\LinkRowCarrier;
use haddowg\JsonApi\Collection\WindowExecutor;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\FilterHandlerInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * The reference {@see LinkRowQueryInterface}: it reads the members the relation's
 * `identifiedBy()` closure returns, each with its link row, and filters, sorts and pages
 * them in memory through the reference {@see ArrayFilterHandler} and
 * {@see ArraySortHandler}, a column reading the row's column with any `pivot.` prefix
 * stripped. Ties keep the closure's order. Core orders an identified relation's linkage
 * by its default sort through it, and a host whose rows are already loaded can use it as
 * is. It is not a production query layer over large link tables.
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
     * @return list<LinkRowCarrier>
     */
    private function rows(mixed $rows): array
    {
        return \is_array($rows) ? \array_values(\array_filter($rows, static fn(mixed $row): bool => $row instanceof LinkRowCarrier)) : [];
    }
}
