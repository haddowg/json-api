<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\WhereSet;
use haddowg\JsonApi\Resource\Filter\WhereSetBuilder;
use haddowg\JsonApi\Resource\Filter\WhereSetMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WhereSet::class)]
#[CoversClass(WhereSetBuilder::class)]
#[CoversClass(ArrayFilterHandler::class)]
final class WhereSetTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, WhereSetMatch}>
     */
    public static function values(): iterable
    {
        yield 'true' => ['true', WhereSetMatch::Set];
        yield 'false' => ['false', WhereSetMatch::NotSet];
        yield 'one' => ['1', WhereSetMatch::Set];
        yield 'empty is false' => ['', WhereSetMatch::NotSet];
        yield 'both, delimited' => ['true,false', WhereSetMatch::Either];
        yield 'both, array' => [['true', 'false'], WhereSetMatch::Either];
        yield 'repeated' => [['yes', 'on'], WhereSetMatch::Set];
        yield 'a default list' => [[false], WhereSetMatch::NotSet];
        yield 'empty list' => [[], WhereSetMatch::Neither];
    }

    #[Test]
    #[DataProvider('values')]
    public function aValueReadsAsAMatch(mixed $value, WhereSetMatch $match): void
    {
        self::assertSame($match, WhereSet::make('archived', 'archivedTs')->build()->match($value));
    }

    #[Test]
    public function aNonBooleanIsA400(): void
    {
        try {
            WhereSet::make('archived')->build()->match('true,banana');
            self::fail('Expected FilterValueInvalid.');
        } catch (FilterValueInvalid $e) {
            self::assertSame('archived', $e->filterKey);
        }
    }

    #[Test]
    public function aNamedMemberIsA400(): void
    {
        $this->expectException(FilterValueInvalid::class);

        WhereSet::make('archived')->build()->match(['x' => 'true']);
    }

    #[Test]
    public function itIsADelimitedListValidatedAsBooleans(): void
    {
        $filter = WhereSet::make('archived', 'archivedTs')->build();

        self::assertTrue($filter->listValue()->isDelimited());
        self::assertSame('archivedTs', $filter->targetColumn());
        self::assertEquals([WhereSet::booleanConstraint()], $filter->constraints());
    }

    #[Test]
    public function aDefaultIsHeldAsBooleansAndCanBeRemoved(): void
    {
        self::assertSame([false], WhereSet::make('archived')->default(false)->build()->defaultValue());
        self::assertSame([false], WhereSet::make('archived')->default([false])->build()->defaultValue());
        self::assertSame([true, false], WhereSet::make('archived')->default('true,false')->build()->defaultValue());

        $filter = WhereSet::make('archived')->default(false)->build();
        self::assertTrue($filter->hasDefault());
        self::assertFalse($filter->withoutDefault()->hasDefault());
        self::assertFalse(WhereSet::make('archived')->build()->hasDefault());
    }

    #[Test]
    public function anExampleIsHeldAsBooleans(): void
    {
        $filter = WhereSet::make('archived')->example('true')->build();

        self::assertTrue($filter->hasExample());
        self::assertSame([true], $filter->getExample());
    }

    #[Test]
    public function aDefaultThatIsNotABooleanFailsTheBuild(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WhereSet filter "archived" declares a default');

        WhereSet::make('archived')->default('maybe')->build();
    }

    #[Test]
    public function theInMemoryHandlerComparesTheColumnsNullness(): void
    {
        $rows = [
            ['id' => 1, 'archivedTs' => null],
            ['id' => 2, 'archivedTs' => '2026-11-01T00:00:00Z'],
        ];
        $filter = WhereSet::make('archived', 'archivedTs')->default(false)->build();
        $handler = new ArrayFilterHandler();
        $ids = static fn(array $rows): array => \array_column($rows, 'id');

        self::assertSame([2], $ids($handler->apply($filter, $rows, 'true')));
        self::assertSame([1], $ids($handler->apply($filter, $rows, 'false')));
        self::assertSame([1, 2], $ids($handler->apply($filter, $rows, ['true', 'false'])));
        self::assertSame([], $ids($handler->apply($filter, $rows, [])));

        $folded = FilterDefaults::apply([], [$filter]);
        self::assertSame([1], $ids($handler->apply($filter, $rows, $folded['archived'])));
    }
}
