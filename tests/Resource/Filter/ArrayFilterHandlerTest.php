<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\UnsupportedFilter;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNotNull;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayFilterHandler::class)]
#[CoversClass(Where::class)]
#[CoversClass(WhereIn::class)]
#[CoversClass(WhereNotIn::class)]
#[CoversClass(WhereIdIn::class)]
#[CoversClass(WhereIdNotIn::class)]
#[CoversClass(WhereNull::class)]
#[CoversClass(WhereNotNull::class)]
#[CoversClass(WhereHas::class)]
#[CoversClass(WhereDoesntHave::class)]
#[CoversClass(UnsupportedFilter::class)]
#[Group('spec:filtering')]
final class ArrayFilterHandlerTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function data(): array
    {
        return [
            ['id' => '1', 'status' => 'draft', 'views' => 10, 'deletedAt' => null],
            ['id' => '2', 'status' => 'published', 'views' => 50, 'deletedAt' => '2020-01-01'],
            ['id' => '3', 'status' => 'published', 'views' => 5, 'deletedAt' => null],
        ];
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $result): array
    {
        self::assertIsArray($result);
        $ids = [];
        foreach ($result as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id']);
            $ids[] = $row['id'];
        }

        return $ids;
    }

    #[Test]
    public function whereEquals(): void
    {
        $result = (new ArrayFilterHandler())->apply(Where::make('status'), $this->data(), 'published');

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function whereGreaterThan(): void
    {
        $result = (new ArrayFilterHandler())->apply(Where::make('views', operator: '>'), $this->data(), 9);

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function whereLikeContainsCaseInsensitively(): void
    {
        // `like` is contains with ASCII case-folding — the semantics a SQL
        // `LIKE '%…%'` gives on common backends, so database adapters can
        // match this reference behaviour.
        $filter = Where::make('status', operator: 'like');

        self::assertSame(['2', '3'], $this->ids(
            (new ArrayFilterHandler())->apply($filter, $this->data(), 'PUBLISH'),
        ));
        self::assertSame([], $this->ids(
            (new ArrayFilterHandler())->apply($filter, $this->data(), 'missing'),
        ));
    }

    #[Test]
    public function whereWithDeserializer(): void
    {
        $filter = Where::make('views')->deserializeUsing(static function (mixed $v): int {
            self::assertIsString($v);

            return (int) $v;
        });
        $result = (new ArrayFilterHandler())->apply($filter, $this->data(), '50');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function whereInFromCommaString(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereIn::make('status'), $this->data(), 'draft,published');

        self::assertSame(['1', '2', '3'], $this->ids($result));
    }

    #[Test]
    public function whereNotIn(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereNotIn::make('status'), $this->data(), 'draft');

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function whereIdIn(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereIdIn::make(), $this->data(), '1,3');

        self::assertSame(['1', '3'], $this->ids($result));
    }

    #[Test]
    public function whereIdNotIn(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereIdNotIn::make(), $this->data(), '1');

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function whereNull(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereNull::make('deletedAt'), $this->data(), '1');

        self::assertSame(['1', '3'], $this->ids($result));
    }

    #[Test]
    public function whereNotNull(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereNotNull::make('deletedAt'), $this->data(), '1');

        self::assertSame(['2'], $this->ids($result));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationData(): array
    {
        return [
            // a non-empty related collection
            ['id' => '1', 'comments' => [['id' => 'c1'], ['id' => 'c2']]],
            // an empty related collection
            ['id' => '2', 'comments' => []],
            // a present to-one
            ['id' => '3', 'comments' => [], 'author' => ['id' => 'a1']],
            // a null to-one and no comments key at all
            ['id' => '4', 'author' => null],
        ];
    }

    #[Test]
    public function whereHasKeepsRowsWithANonEmptyRelatedCollection(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereHas::make('comments'), $this->relationData(), '');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereDoesntHaveKeepsRowsWithoutARelatedCollection(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereDoesntHave::make('comments'), $this->relationData(), '');

        // empty collection, no key, and the null-to-one row all lack comments.
        self::assertSame(['2', '3', '4'], $this->ids($result));
    }

    #[Test]
    public function whereHasTreatsAPresentToOneAsExisting(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereHas::make('author'), $this->relationData(), '');

        self::assertSame(['3'], $this->ids($result));
    }

    #[Test]
    public function whereDoesntHaveTreatsANullToOneAsMissing(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereDoesntHave::make('author'), $this->relationData(), '');

        // row 4 has author: null; rows 1 and 2 have no author key; row 3 has one.
        self::assertSame(['1', '2', '4'], $this->ids($result));
    }

    #[Test]
    public function relationshipFilterReadsTheRelationshipNameNotTheKey(): void
    {
        // The declaration key and the traversed relationship name can differ;
        // existence is read off the relationship, not the filter key.
        $result = (new ArrayFilterHandler())->apply(WhereHas::make('hasComments', 'comments'), $this->relationData(), '');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereHasCountsACountableRelation(): void
    {
        $data = [
            ['id' => '1', 'comments' => new \ArrayIterator([['id' => 'c1']])],
            ['id' => '2', 'comments' => new \ArrayIterator([])],
        ];

        $result = (new ArrayFilterHandler())->apply(WhereHas::make('comments'), $data, '');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function relationshipFilterIgnoresTheRequestValue(): void
    {
        // Whatever the client sends, only presence decides the match.
        $filter = WhereHas::make('comments');

        self::assertSame(
            $this->ids((new ArrayFilterHandler())->apply($filter, $this->relationData(), 'true')),
            $this->ids((new ArrayFilterHandler())->apply($filter, $this->relationData(), 'anything')),
        );
    }

    #[Test]
    public function unsupportedFilterThrows500(): void
    {
        $filter = new class implements FilterInterface {
            public function key(): string
            {
                return 'bespoke';
            }
        };

        try {
            (new ArrayFilterHandler())->apply($filter, $this->data(), '1');
            self::fail('Expected UnsupportedFilter.');
        } catch (UnsupportedFilter $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertSame($filter, $e->filter);
            self::assertCount(1, $e->getErrors());
        }
    }
}
