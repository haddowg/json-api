<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Through\RelationPath;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so the admission closure an adapter hands the reference
 * handlers keeps accepting the shapes an adapter writes: the walked path and the rows,
 * with or without the relation a `->through()` hop walks, the morph admission closure the
 * polymorphic relation, the requested type and its targets' keys, and the relation reader
 * the row, the walked path and the relation, returning rows or null.
 */
final class ThroughAdmissionTypes
{
    public static function filters(ArrayFilterHandler $handler): ArrayFilterHandler
    {
        return $handler
            ->withRowAdmission(static fn(string $path, array $rows): array => $path === 'hidden' ? [] : $rows)
            ->withRowAdmission(static fn(string $path, array $rows, ?RelationInterface $relation = null): array => $relation?->name() === 'hidden' ? [] : $rows)
            ->withMorphAdmission(static fn(RelationInterface $relation, string $type, array $keys): array => $type === 'hidden' ? [] : $keys)
            ->withRelationReader(static fn(mixed $row, string $path, ?RelationInterface $relation): ?array => $path === 'notes' ? [] : null);
    }

    /**
     * @return list<mixed>
     */
    public static function reach(RelationPath $path, mixed $row): array
    {
        return $path->reach(
            $row,
            static fn(string $walked, array $rows): array => $rows,
            static fn(mixed $current, string $walked, ?RelationInterface $relation): ?iterable => $relation?->name() === 'notes' ? new \ArrayIterator([]) : null,
        );
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
        )->withRelationReader(static fn(mixed $row, string $path, ?RelationInterface $relation): ?array => null);
    }
}
