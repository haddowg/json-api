<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A relation reader hands the in-memory handler the rows a hop reaches when the model
 * holds none, as a relation read through a foreign key is, and the model's value is read
 * whenever it returns null.
 */
#[CoversClass(ArrayFilterHandler::class)]
final class InMemoryRelationReaderTest extends TestCase
{
    #[Test]
    public function existenceFiltersReadTheRowsTheReaderReturns(): void
    {
        $rows = [['id' => 1], ['id' => 2], ['id' => 3, 'tags' => ['x']]];
        $notes = [1 => [['title' => 'One']], 2 => []];
        $asked = [];
        $handler = (new ArrayFilterHandler())->withRelationReader(static function (mixed $row, string $path, ?RelationInterface $relation) use ($notes, &$asked): ?array {
            $asked[] = $path;
            $id = \is_array($row) && \is_int($row['id'] ?? null) ? $row['id'] : 0;

            return $path === 'notes' ? ($notes[$id] ?? []) : null;
        });
        $ids = static fn(array $kept): array => \array_column($kept, 'id');

        self::assertSame([1], $ids($handler->apply(WhereHas::make('hasNotes', 'notes'), $rows, 'true')));
        self::assertSame([2, 3], $ids($handler->apply(WhereDoesntHave::make('noNotes', 'notes'), $rows, 'true')));
        self::assertSame([3], $ids($handler->apply(WhereHas::make('hasTags', 'tags'), $rows, 'true')));
        self::assertContains('tags', $asked);
    }

    #[Test]
    public function aTraversalReadsEachHopThroughTheReader(): void
    {
        $rows = [['id' => 1], ['id' => 2]];
        $handler = (new ArrayFilterHandler())->withRelationReader(static fn(mixed $row, string $path, ?RelationInterface $relation): ?array => $path === 'notes' && \is_array($row) && ($row['id'] ?? null) === 2 ? [['title' => 'Two']] : null);

        self::assertSame([2], \array_column($handler->apply(WhereThrough::make('noteTitle', 'notes.title')->build(), $rows, 'Two'), 'id'));
    }
}
