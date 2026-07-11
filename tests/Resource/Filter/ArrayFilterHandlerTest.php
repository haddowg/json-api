<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterArmInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\Range;
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
#[CoversClass(Range::class)]
#[CoversClass(DateRange::class)]
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
        $result = (new ArrayFilterHandler())->apply(Where::make('status')->build(), $this->data(), 'published');

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function whereGreaterThan(): void
    {
        $result = (new ArrayFilterHandler())->apply(Where::make('views', operator: '>')->build(), $this->data(), 9);

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function whereLikeContainsCaseInsensitively(): void
    {
        // `like` is contains with ASCII case-folding — the semantics a SQL
        // `LIKE '%…%'` gives on common backends, so database adapters can
        // match this reference behaviour.
        $filter = Where::make('status', operator: 'like')->build();

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
        })->build();
        $result = (new ArrayFilterHandler())->apply($filter, $this->data(), '50');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function whereInFromCommaString(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereIn::make('status')->build(), $this->data(), 'draft,published');

        self::assertSame(['1', '2', '3'], $this->ids($result));
    }

    #[Test]
    public function whereNotIn(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereNotIn::make('status')->build(), $this->data(), 'draft');

        self::assertSame(['2', '3'], $this->ids($result));
    }

    #[Test]
    public function whereIdIn(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereIdIn::make()->build(), $this->data(), '1,3');

        self::assertSame(['1', '3'], $this->ids($result));
    }

    #[Test]
    public function whereIdNotIn(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereIdNotIn::make()->build(), $this->data(), '1');

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

    // --- ordered comparison against null never matches (ADR 0116) --------------

    /**
     * A dataset carrying a null-bearing numeric column, used only by the
     * null-semantics tests (the shared {@see data()} stays null-free).
     *
     * @return list<array<string, mixed>>
     */
    private function nullBearingData(): array
    {
        return [
            ['id' => '1', 'views' => 10],
            ['id' => '2', 'views' => 5],
            ['id' => 'N', 'views' => null],
        ];
    }

    #[Test]
    public function whereGreaterThanExcludesNull(): void
    {
        // Under PHP coercion `null > -1` was true (null -> 0); an ordered
        // comparison now never matches a null column, so only the non-null rows
        // (which are also in range) remain — the exclusion is null-specific.
        $result = (new ArrayFilterHandler())->apply(Where::make('views', operator: '>')->build(), $this->nullBearingData(), -1);

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function whereGreaterThanOrEqualExcludesNull(): void
    {
        // `null >= 0` was true under coercion; the null row is now excluded while
        // the non-null rows still match.
        $result = (new ArrayFilterHandler())->apply(Where::make('views', operator: '>=')->build(), $this->nullBearingData(), 0);

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function whereLessThanExcludesNull(): void
    {
        // `null < 100` was true under coercion; the null row is now excluded.
        $result = (new ArrayFilterHandler())->apply(Where::make('views', operator: '<')->build(), $this->nullBearingData(), 100);

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function whereLessThanOrEqualExcludesNull(): void
    {
        // `null <= 100` was true under coercion; the null row is now excluded.
        $result = (new ArrayFilterHandler())->apply(Where::make('views', operator: '<=')->build(), $this->nullBearingData(), 100);

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function rangeExcludesNullColumn(): void
    {
        // A null column falls within no present bound (SQL UNKNOWN), and the raw
        // value is read before the deserializer so a null->0 mapping cannot
        // smuggle it in; the non-null in-range rows still match.
        $result = (new ArrayFilterHandler())->apply(
            Range::make('views')->build(),
            $this->nullBearingData(),
            ['min' => '0', 'max' => '100'],
        );

        self::assertSame(['1', '2'], $this->ids($result));
    }

    #[Test]
    public function dateRangeExcludesNullColumn(): void
    {
        // A null date column against a present bound is excluded, exactly as a
        // SQL adapter's three-valued logic excludes it.
        $data = [
            ['id' => '1', 'published' => '2020-06-01'],
            ['id' => 'N', 'published' => null],
        ];

        $result = (new ArrayFilterHandler())->apply(
            DateRange::make('published')->build(),
            $data,
            ['min' => '2020-01-01', 'max' => '2020-12-31'],
        );

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function rangeExcludesAColumnADeserializerMapsToNull(): void
    {
        // A non-null raw the deserializer maps to null (a sentinel) must not slip
        // through a max-only bound: `null <= max` coerces to true in PHP, so the
        // post-deserialize guard excludes it, matching a SQL NULL. See ADR 0116.
        $data = [
            ['id' => '1', 'views' => '5'],
            ['id' => 'S', 'views' => 'unknown'],
        ];
        $filter = Range::make('views')->deserializeUsing(static function (mixed $v): ?int {
            self::assertIsString($v);

            return $v === 'unknown' ? null : (int) $v;
        })->build();

        $result = (new ArrayFilterHandler())->apply($filter, $data, ['max' => '100']);

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereThroughLeafComparisonExcludesNull(): void
    {
        // A reachable leaf whose value is null never satisfies an ordered
        // compare(): under coercion `null >= 0` matched, now it does not, so only
        // the non-null in-range row remains.
        $data = [
            ['id' => '1', 'author' => ['age' => 30]],
            ['id' => 'N', 'author' => ['age' => null]],
        ];
        $filter = WhereThrough::make('author.age')->operator('>=')->deserializeUsing(static function (mixed $v): int {
            self::assertIsString($v);

            return (int) $v;
        })->build();

        $result = (new ArrayFilterHandler())->apply($filter, $data, '0');

        self::assertSame(['1'], $this->ids($result));
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
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('author.name')->build(), $this->traversalData(), 'Ada');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereThroughIsExistsAnyAcrossAToManyHop(): void
    {
        // Keeps a row that has *some* comment whose body matches.
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('comments.body')->build(), $this->traversalData(), 'second');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereThroughChainsMultipleHops(): void
    {
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('author.company.name')->build(), $this->traversalData(), 'Globex');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function whereThroughAppliesTheFluentOperator(): void
    {
        $filter = WhereThrough::make('author.name')->operator('like')->build();
        $result = (new ArrayFilterHandler())->apply($filter, $this->traversalData(), 'AD');

        self::assertSame(['1'], $this->ids($result));
    }

    #[Test]
    public function whereThroughUsesTheNamedKeyOverridePathDistinctly(): void
    {
        // The key and the traversal path differ; traversal reads the path, not the key.
        $filter = WhereThrough::make('topAuthor', 'author.name')->build();
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
        })->build();

        $result = (new ArrayFilterHandler())->apply($filter, $data, '40');

        self::assertSame(['2'], $this->ids($result));
    }

    #[Test]
    public function whereThroughEmptyOrMissingHopMatchesNothing(): void
    {
        // Row 3 has a null author; no reachable leaf, so it never matches.
        $result = (new ArrayFilterHandler())->apply(WhereThrough::make('author.name')->build(), $this->traversalData(), 'Nobody');

        self::assertSame([], $this->ids($result));
    }

    #[Test]
    public function whereThroughDeclaresItsValueConstraints(): void
    {
        $filter = WhereThrough::make('author.age')->integer()->build();

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
    public function anOptionalHintIsAppendedToTheMessage(): void
    {
        $filter = $this->bespokeFilter('bespoke');

        // No hint: the bare message (the default core behaviour — core names no data layer).
        self::assertSame(
            'No handler is registered for filter "bespoke" (' . $filter::class . ').',
            (new UnsupportedFilter($filter))->getMessage(),
        );

        // A raising handler may append data-layer-specific remediation guidance.
        $withHint = new UnsupportedFilter($filter, 'Register an arm for it.');
        self::assertSame(
            'No handler is registered for filter "bespoke" (' . $filter::class . '). Register an arm for it.',
            $withHint->getMessage(),
        );
        self::assertSame('Register an arm for it.', $withHint->hint);
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
