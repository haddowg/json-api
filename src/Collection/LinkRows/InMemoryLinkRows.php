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
     * Applies `$plan` to `$parent`'s link rows held in memory, the relation's value on the
     * parent at its `storedAs()` field or name, and returns the parent. Each pass runs as
     * the plan orders them: the deletes, the temporary positions, the updates, then the
     * inserts, each new row made by `$newRow`, or as a `through()` instance built with no
     * arguments, and set field by field. The rows are written back as a list.
     *
     * It checks what a unique owner and position key would: a pass that leaves two of the
     * parent's rows at one position is a `\LogicException`, so the plan is witnessed as
     * well as applied.
     *
     * @param (\Closure(): mixed)|null $newRow
     *
     * @throws \LogicException when the relation was not declared with identifiedByRows(), the
     *                         parent's rows are not iterable, or a pass collides
     */
    public function write(MorphToMany $relation, mixed $parent, LinkRowWritePlan $plan, ?\Closure $newRow = null): mixed
    {
        $fields = $relation->linkRowFields()
            ?? throw new \LogicException(\sprintf('MorphToMany relation "%s" was not declared with identifiedByRows(), so it has no link-row writes to apply.', $relation->name()));
        $member = $relation->column() ?? $relation->name();
        $held = Accessor::get($parent, $member) ?? [];
        if (!\is_iterable($held)) {
            throw new \LogicException(\sprintf('MorphToMany relation "%s" holds its link rows at "%s", which is %s; it must be iterable.', $relation->name(), $member, \get_debug_type($held)));
        }

        $rows = [];
        foreach ($held as $row) {
            $rows[] = $row;
        }
        $position = LinkRowWritePlan::positionColumn($relation);
        $indexOf = static function (array $rows, IdentifiedMember $target) use ($fields): ?int {
            foreach ($rows as $index => $row) {
                $linked = $fields->member($row);
                if ($linked !== null && $linked->type === $target->type && (string) $linked->id === (string) $target->id) {
                    return $index;
                }
            }

            return null;
        };
        $set = static function (array $rows, LinkRowWrite $write) use ($indexOf): array {
            $index = $indexOf($rows, $write->member);
            if ($index !== null) {
                foreach ($write->values as $field => $value) {
                    $rows[$index] = Accessor::set($rows[$index], $field, $value);
                }
            }

            return $rows;
        };

        foreach ($plan->deletes as $deleted) {
            $index = $indexOf($rows, $deleted);
            if ($index !== null) {
                unset($rows[$index]);
                $rows = \array_values($rows);
            }
        }

        foreach ([$plan->temporary, $plan->updates] as $pass) {
            foreach ($pass as $write) {
                $rows = $set($rows, $write);
            }
            $this->assertUniquePositions($relation, $rows, $position);
        }

        $class = $fields->through;
        $newRow ??= static fn(): mixed => new $class();
        foreach ($plan->inserts as $insert) {
            $row = $newRow();
            foreach ($insert->values as $field => $value) {
                $row = Accessor::set($row, $field, $value);
            }
            $rows[] = $row;
        }
        $this->assertUniquePositions($relation, $rows, $position);

        return Accessor::set($parent, $member, $rows);
    }

    /**
     * @param array<mixed> $rows
     */
    private function assertUniquePositions(MorphToMany $relation, array $rows, ?string $position): void
    {
        if ($position === null) {
            return;
        }

        $seen = [];
        foreach ($rows as $row) {
            $at = Accessor::get($row, $position);
            if (!\is_int($at)) {
                continue;
            }
            if (isset($seen[$at])) {
                throw new \LogicException(\sprintf('Writing the link rows of MorphToMany relation "%s" put two rows at position %d, which a unique owner and position key would reject.', $relation->name(), $at));
            }
            $seen[$at] = true;
        }
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
