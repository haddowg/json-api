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
     * @param iterable<ArraySortArmInterface> $arms author arms for custom sort types, consulted in order
     */
    public function __construct(iterable $arms = [])
    {
        $this->arms = \is_array($arms) ? \array_values($arms) : \iterator_to_array($arms, false);
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
        if ($sort instanceof SortByField) {
            $column = $sort->column;

            return static fn(mixed $row): mixed => Accessor::get($row, $column);
        }

        foreach ($this->arms as $arm) {
            if ($arm->supports($sort)) {
                return static fn(mixed $row): mixed => $arm->value($sort, $row);
            }
        }

        throw new UnsupportedSort($sort);
    }
}
