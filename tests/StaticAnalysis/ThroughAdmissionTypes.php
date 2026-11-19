<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so the admission closure an adapter hands the reference
 * handlers keeps accepting the shapes an adapter writes: the walked path and the rows,
 * with or without the relation a `->through()` hop walks.
 */
final class ThroughAdmissionTypes
{
    public static function filters(ArrayFilterHandler $handler): ArrayFilterHandler
    {
        return $handler
            ->withRowAdmission(static fn(string $path, array $rows): array => $path === 'hidden' ? [] : $rows)
            ->withRowAdmission(static fn(string $path, array $rows, ?RelationInterface $relation = null): array => $relation?->name() === 'hidden' ? [] : $rows);
    }

    public static function sorts(ArraySortHandler $handler): ArraySortHandler
    {
        return $handler->withRowAdmission(
            /**
             * @param list<mixed> $rows
             *
             * @return list<mixed>
             */
            static fn(string $path, array $rows, ?RelationInterface $relation = null): array => $rows,
        );
    }
}
