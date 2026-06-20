<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface;
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
use haddowg\JsonApi\Resource\Filter\WhereThrough;
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
#[CoversClass(WhereThrough::class)]
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

    /**
     * @return list<array<string, mixed>>
     */
    private function traversalData(): array
    {
        return [
            // to-one author, multi-hop author.company
            [
                'id' => '1',
                'author' => ['name' => 'Ada', 'company' => ['name' => 'Acme']],
                'comments' => [['body' => 'first'], ['body' => 'second']],
            ],
            [
                'id' => '2',
                'author' => ['name' => 'Bob', 'company' => ['name' => 'Globex']],
                'comments' => [['body' => 'third']],
            ],
            // no author, empty comments
            ['id' => '3', 'author' => null, 'comments' => []],
        ];
    }

    #[Test]
    public function whereThroughMatchesASingleToOneHop(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('author.name'), $this->traversalData(), 'Ada');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereThroughIsExistsAnyAcrossAToManyHop(): void
    {
        // Keeps a row that has *some* comment whose body matches.
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('comments.body'), $this->traversalData(), 'second');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereThroughChainsMultipleHops(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('author.company.name'), $this->traversalData(), 'Globex');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function whereThroughAppliesTheFluentOperator(): void
    {
        $filter = WhereThrough::make('author.name')->operator('like');
        $result = (new ArrayFilterHandler())->apply($filter, $this->traversalData(), 'AD');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereThroughUsesTheNamedKeyOverridePathDistinctly(): void
    {
        // The key and the traversal path differ; traversal reads the path, not the key.
        $filter = WhereThrough::make('topAuthor', 'author.name');
        self::assertSame('topAuthor', $filter->key());

        $result = (new ArrayFilterHandler())->apply($filter, $this->traversalData(), 'Bob');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function whereThroughAppliesTheDeserializerBeforeComparing(): void
    {
        $data = [
            ['id' => '1', 'author' => ['age' => 30]],
            ['id' => '2', 'author' => ['age' => 50]],
        ];
        $filter = WhereThrough::make('author.age')->operator('>=')->deserializeUsing(static function (mixed $v): int {
            self::assertIsString($v);

            return (int) $v;
        });

        $result = (new ArrayFilterHandler())->apply($filter, $data, '40');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function whereThroughEmptyOrMissingHopMatchesNothing(): void
    {
        // Row 3 has a null author; no reachable leaf, so it never matches.
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('author.name'), $this->traversalData(), 'Nobody');

        self::assertSame([], $this->ids($result));
    }

    #[Test]
    public function whereThroughDeclaresItsValueConstraints(): void
    {
        $filter = WhereThrough::make('author.age')->integer();

        self::assertCount(1, $filter->constraints());
    }

    #[Test]
    public function unsupportedFilterThrows500(): void
    {
        $filter = new class implements FilterInterface {
            public function key(): string
            {
                return 'bespoke';
            }

            public function constraints(): array
            {
                return [];
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

    #[Test]
    public function customFilterRunsThroughARegisteredArm(): void
    {
        $filter = $this->bespokeFilter('minViews');
        $arm = new class implements ArrayFilterArmInterface {
            public function supports(FilterInterface $filter): bool
            {
                return $filter->key() === 'minViews';
            }

            public function predicate(FilterInterface $filter, mixed $value): \Closure
            {
                $min = \is_string($value) ? (int) $value : 0;

                return static function (mixed $row) use ($min): bool {
                    if (!\is_array($row)) {
                        return false;
                    }
                    $views = $row['views'] ?? null;

                    return \is_int($views) && $views >= $min;
                };
            }
        };

        $result = (new ArrayFilterHandler([$arm]))->apply($filter, $this->data(), '10');

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function aFilterNoBuiltInOrArmRecognisesStillThrows(): void
    {
        $arm = new class implements ArrayFilterArmInterface {
            public function supports(FilterInterface $filter): bool
            {
                return false;
            }

            public function predicate(FilterInterface $filter, mixed $value): \Closure
            {
                return static fn(): bool => true;
            }
        };

        $this->expectException(UnsupportedFilter::class);

        (new ArrayFilterHandler([$arm]))->apply($this->bespokeFilter('nope'), $this->data(), '1');
    }

    private function bespokeFilter(string $key): FilterInterface
    {
        return new class ($key) implements FilterInterface {
            public function __construct(private readonly string $key) {}

            public function key(): string
            {
                return $this->key;
            }

            public function constraints(): array
            {
                return [];
            }
        };
    }
}
