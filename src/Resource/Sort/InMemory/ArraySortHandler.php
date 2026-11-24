<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort\InMemory;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortHandlerInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;

/**
 * Reference {@see SortHandlerInterface} operating on a PHP `list<array|object>`:
 * one `usort` whose comparator cascades through the directives in significance
 * order, so the request's first sort field is the primary key. For tests and
 * worked examples; not a production sort layer.
 *
 * A custom {@see SortInterface} the handler does not recognise (it sorts only by a
 * declared {@see SortByField}) is delegated to a registered
 * {@see ArraySortArmInterface} (constructor-injected, first
 * {@see ArraySortArmInterface::supports()} match wins), which contributes the
 * directive's per-row sort key, before {@see UnsupportedSort} is raised — the
 * in-memory half of the framework's extensible-handler seam.
 *
 * @implements SortHandlerInterface<list<mixed>>
 */
final class ArraySortHandler implements SortHandlerInterface
{
    /**
     * @var list<ArraySortArmInterface>
     */
    private readonly array $arms;

    /**
     * @var (\Closure(string, list<mixed>, ?\haddowg\JsonApi\Resource\Field\RelationInterface=): list<mixed>)|null
     */
    private ?\Closure $admit = null;

    /**
     * @var (\Closure(mixed, string, ?\haddowg\JsonApi\Resource\Field\RelationInterface): (iterable<mixed>|null))|null
     */
    private ?\Closure $read = null;

    /**
     * @param iterable<ArraySortArmInterface> $arms author arms for custom sort types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
    }

    /**
     * A copy that narrows the row each hop of a `->through()` sort reaches to the ones the
     * reached type's scope admits, so a row its scope hides sorts as `null`. `$admit` takes
     * the same arguments as {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler::withRowAdmission()}'s.
     *
     * @param \Closure(string, list<mixed>, ?\haddowg\JsonApi\Resource\Field\RelationInterface=): list<mixed> $admit
     */
    public function withRowAdmission(\Closure $admit): self
    {
        $copy = clone $this;
        $copy->admit = $admit;

        return $copy;
    }

    /**
     * A copy that reads the rows each hop of a `->through()` sort reaches through `$read`,
     * as {@see \haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler::withRelationReader()}
     * does for filters, so a to-one read through a foreign key sorts by the row its data
     * layer reads.
     *
     * @param \Closure(mixed, string, ?\haddowg\JsonApi\Resource\Field\RelationInterface): (iterable<mixed>|null) $read
     */
    public function withRelationReader(\Closure $read): self
    {
        $copy = clone $this;
        $copy->read = $read;

        return $copy;
    }

    public function apply(array $sorts, mixed $query): mixed
    {
        /** @var list<array{\Closure(mixed): mixed, bool}> $keys */
        $keys = [];
        foreach ($sorts as $directive) {
            $keys[] = [$this->keyExtractor($directive->sort), $directive->descending];
        }

        if (!\is_array($query)) {
            $query = [];
        }

        if ($keys === []) {
            return $query;
        }

        /** @var list<mixed> $query */
        \usort($query, static function (mixed $a, mixed $b) use ($keys): int {
            foreach ($keys as [$key, $descending]) {
                $cmp = $key($a) <=> $key($b);
                if ($cmp !== 0) {
                    return $descending ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        return $query;
    }

    /**
     * The per-row sort-key extractor for one directive: a declared field reads off
     * the row via {@see Accessor}; any other {@see SortInterface} is delegated to the
     * first registered arm that {@see ArraySortArmInterface::supports()} it, and
     * {@see UnsupportedSort} when none does (the same signal the built-in gave).
     *
     * @return \Closure(mixed): mixed
     */
    private function keyExtractor(SortInterface $sort): \Closure
    {
        if ($sort instanceof SortByField && $sort->through !== null) {
            $admit = $this->admit;
            $read = $this->read;

            return static fn(mixed $row): mixed => $sort->valueOf($row, $admit, $read);
        }

        if ($sort instanceof SortByField) {
            $column = $sort->column;

            // A backed enum case, as an enum() field hydrates, sorts by its backing value.
            return static function (mixed $row) use ($column): mixed {
                $value = Accessor::get($row, $column);

                return $value instanceof \BackedEnum ? $value->value : $value;
            };
        }

        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                return static fn(mixed $row): mixed => $arm->value($sort, $row);
            }
        }

        throw new UnsupportedSort($sort);
    }
}
