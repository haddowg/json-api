<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Collection\Keyset;

use haddowg\JsonApi\Collection\Keyset\KeysetColumn;
use haddowg\JsonApi\Collection\Keyset\KeysetResolver;
use haddowg\JsonApi\Exception\CursorStale;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\UnsupportedSort;
use haddowg\JsonApi\Tests\Collection\Keyset\Fixture\MetaValueSort;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared keyset-column resolver: reads the active sort from the same inputs as the
 * plain sort path (same 400s), appends the primary key as the final total-order column,
 * and enforces cursor staleness. Every data layer resolves columns from this one class
 * so SQL and PHP windowing cannot drift.
 *
 * @internal
 */
#[CoversClass(KeysetResolver::class)]
#[Group('spec:pagination')]
final class KeysetResolverTest extends TestCase
{
    private KeysetResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new KeysetResolver();
    }

    #[Test]
    public function aPkOnlyKeysetUsesTheDefaultPkDirection(): void
    {
        $columns = $this->resolver->resolve([], [], [], 'id');
        self::assertEquals([new KeysetColumn('id', false)], $columns);

        $descending = $this->resolver->resolve([], [], [], 'id', pkDefaultDescending: true);
        self::assertEquals([new KeysetColumn('id', true)], $descending);
    }

    #[Test]
    public function itAppendsThePkAfterTheActiveSortFollowingTheLastDirection(): void
    {
        // A trailing descending directive makes the appended PK tiebreak descending too,
        // so the total order stays monotone.
        self::assertEquals(
            [new KeysetColumn('name', true), new KeysetColumn('id', true)],
            $this->resolver->resolve(['-name'], [SortByField::make('name')], [], 'id'),
        );
    }

    #[Test]
    public function itDoesNotAppendThePkWhenTheClientAlreadySortsByIt(): void
    {
        self::assertEquals(
            [new KeysetColumn('id', false)],
            $this->resolver->resolve(['id'], [SortByField::make('id')], [], 'id'),
        );
    }

    #[Test]
    public function itRejectsANonFieldSort(): void
    {
        $computed = new class implements SortInterface {
            public function key(): string
            {
                return 'computed';
            }
        };

        $this->expectException(UnsupportedSort::class);

        $this->resolver->resolve(['computed'], [$computed], [], 'id');
    }

    #[Test]
    public function itValidatesTheRequestedSortLikeThePlainPath(): void
    {
        try {
            $this->resolver->resolve(['nope'], [SortByField::make('name')], [], 'id');
            self::fail('expected ' . SortParamUnrecognized::class);
        } catch (SortParamUnrecognized) {
        }

        $this->expectException(SortingUnsupported::class);
        $this->resolver->resolve(['name'], [], [], 'id');
    }

    #[Test]
    public function assertFreshAcceptsAMatchingBoundary(): void
    {
        $columns = [new KeysetColumn('name', false), new KeysetColumn('id', false)];
        $boundary = new CursorBoundary(['name' => 'x', 'id' => 1], true, ['name' => false, 'id' => false]);

        $this->resolver->assertFresh($boundary, $columns, 'page[after]');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertFreshRejectsADifferentColumnSet(): void
    {
        $columns = [new KeysetColumn('name', false), new KeysetColumn('id', false)];
        $boundary = new CursorBoundary(['id' => 1], true, ['id' => false]);

        $this->expectException(CursorStale::class);
        $this->resolver->assertFresh($boundary, $columns, 'page[after]');
    }

    #[Test]
    public function assertFreshRejectsAFlippedDirection(): void
    {
        $columns = [new KeysetColumn('name', false), new KeysetColumn('id', false)];
        $boundary = new CursorBoundary(['name' => 'x', 'id' => 1], true, ['name' => true, 'id' => true]);

        $this->expectException(CursorStale::class);
        $this->resolver->assertFresh($boundary, $columns, 'page[after]');
    }

    #[Test]
    public function aKeysetSortBecomesALevelCarryingTheSort(): void
    {
        $cap = new MetaValueSort('cap', 'cap');

        self::assertEquals(
            [new KeysetColumn('title', false), new KeysetColumn('cap', true, $cap), new KeysetColumn('id', true)],
            $this->resolver->resolve(['title', '-cap'], [SortByField::make('title'), $cap], [], 'id'),
        );
    }

    #[Test]
    public function aFamilyKeyResolvesThroughTheRequest(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => new MetaValueSort('value.' . $suffix, $suffix));

        $columns = $this->resolver->resolve(['-value.end_date'], [$family], [], 'id', request: $this->request());

        self::assertCount(2, $columns);
        self::assertSame('value.end_date', $columns[0]->column);
        self::assertTrue($columns[0]->descending);
        self::assertInstanceOf(MetaValueSort::class, $columns[0]->sort);
        self::assertSame('end_date', $columns[0]->sort->member);
        self::assertEquals(new KeysetColumn('id', true), $columns[1]);

        try {
            $this->resolver->resolve(['value'], [$family], [], 'id', request: $this->request());
            self::fail('expected ' . SortParamUnrecognized::class);
        } catch (SortParamUnrecognized $e) {
            self::assertSame('value', $e->sortParam);
        }
    }

    #[Test]
    public function aDefaultSortMayNameAFamilyKey(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => new MetaValueSort('value.' . $suffix, $suffix));
        $default = new MetaValueSort('value.cap', 'cap');

        $columns = $this->resolver->resolve([], [$family], [new SortDirective($default, false)], 'id', request: $this->request());

        self::assertEquals([new KeysetColumn('value.cap', false, $default), new KeysetColumn('id', false)], $columns);

        $this->expectException(SortParamUnrecognized::class);
        $this->resolver->resolve([], [$family], [new SortDirective(new MetaValueSort('other', 'x'), false)], 'id', request: $this->request());
    }

    #[Test]
    public function aFlippedLevelKeepsItsSort(): void
    {
        $cap = new MetaValueSort('cap', 'cap');

        self::assertEquals(new KeysetColumn('cap', true, $cap), (new KeysetColumn('cap', false, $cap))->flipped());
    }

    private function request(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/campaigns'));
    }
}
