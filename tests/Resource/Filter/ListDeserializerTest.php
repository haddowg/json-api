<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\FilterDefaults;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A set filter's deserializer maps the requested members, as one list, to the values the
 * column is compared against.
 */
#[CoversClass(WhereIn::class)]
#[CoversClass(WhereNotIn::class)]
#[CoversClass(ArrayFilterHandler::class)]
final class ListDeserializerTest extends TestCase
{
    private const array ROWS = [
        ['id' => '1', 'kind' => 'k-oak'],
        ['id' => '2', 'kind' => 'k-ash'],
        ['id' => '3', 'kind' => 'k-elm'],
    ];

    /**
     * Requested group names and the stored kinds each one covers.
     */
    private const array GROUPS = [
        'broadleaf' => ['k-oak', 'k-ash'],
        'shade' => ['k-elm'],
        'empty' => [],
    ];

    #[Test]
    public function theDeserializerReceivesTheWholeListInEitherForm(): void
    {
        $seen = [];
        $filter = WhereIn::make('group', 'kind')->acceptsList()->deserializeUsing(static function (array $groups) use (&$seen): array {
            $seen[] = $groups;

            return self::kinds($groups);
        })->build();
        $handler = new ArrayFilterHandler();

        self::assertSame(['1', '2', '3'], self::ids($handler->apply($filter, self::ROWS, 'broadleaf, shade')));
        self::assertSame(['1', '2'], self::ids($handler->apply($filter, self::ROWS, ['broadleaf'])));
        self::assertSame([['broadleaf', 'shade'], ['broadleaf']], $seen);
    }

    #[Test]
    public function aListWithoutAcceptsListIsStillReadLeniently(): void
    {
        $filter = WhereIn::make('group', 'kind')->delimiter('|')->deserializeUsing(self::kinds(...))->build();

        self::assertSame(['3'], self::ids((new ArrayFilterHandler())->apply($filter, self::ROWS, 'shade')));
        self::assertSame(['1', '2', '3'], self::ids((new ArrayFilterHandler())->apply($filter, self::ROWS, 'shade|broadleaf')));
    }

    #[Test]
    public function aWhereNotInExcludesTheDeserializedValues(): void
    {
        $filter = WhereNotIn::make('group', 'kind')->acceptsList()->deserializeUsing(self::kinds(...))->build();

        self::assertSame(['3'], self::ids((new ArrayFilterHandler())->apply($filter, self::ROWS, 'broadleaf')));
    }

    #[Test]
    public function anEmptyDeserializedListMatchesNothingAndExcludesNothing(): void
    {
        $handler = new ArrayFilterHandler();

        self::assertSame([], $handler->apply(WhereIn::make('group', 'kind')->deserializeUsing(self::kinds(...))->build(), self::ROWS, 'empty'));
        self::assertSame(['1', '2', '3'], self::ids($handler->apply(WhereNotIn::make('group', 'kind')->deserializeUsing(self::kinds(...))->build(), self::ROWS, 'empty')));
    }

    #[Test]
    public function aDefaultIsInTheRequestedVocabulary(): void
    {
        $filter = WhereIn::make('group', 'kind')->acceptsList()->default('shade')->deserializeUsing(self::kinds(...))->build();
        $value = FilterDefaults::apply([], [$filter])['group'];

        self::assertSame(['3'], self::ids((new ArrayFilterHandler())->apply($filter, self::ROWS, $value)));
    }

    #[Test]
    public function aKeyedReturnIsComparedAsAList(): void
    {
        // @phpstan-ignore argument.type (a keyed array breaks the declared type, but is still compared)
        $filter = WhereIn::make('group', 'kind')->deserializeUsing(static fn(array $groups): array => ['x' => 'k-ash'])->build();

        self::assertSame(['k-ash'], $filter->comparedValues(['anything']));
    }

    #[Test]
    public function withoutADeserializerTheMembersAreCompared(): void
    {
        self::assertSame(['a', 'b'], WhereIn::make('kind')->build()->comparedValues(['a', 'b']));
        self::assertSame(['a'], WhereNotIn::make('kind')->build()->comparedValues(['a']));
    }

    #[Test]
    public function aCopyKeepsTheDeserializer(): void
    {
        $filter = WhereIn::make('group', 'kind')->default('shade')->deserializeUsing(self::kinds(...))->build();
        $notIn = WhereNotIn::make('group', 'kind')->default('shade')->deserializeUsing(self::kinds(...))->build();

        self::assertSame(['k-elm'], $filter->withoutDefault()->comparedValues(['shade']));
        self::assertSame(['k-elm'], $filter->withThroughPath('owner')->comparedValues(['shade']));
        self::assertSame(['k-elm'], $notIn->withoutDefault()->comparedValues(['shade']));
    }

    #[Test]
    public function aMatchedDirectiveKeepsTheDeserializer(): void
    {
        $directives = FilterMatcher::directives(
            ['group' => 'broadleaf'],
            [FilterFamily::make('group', static fn(string $suffix): WhereIn => WhereIn::make('group.' . $suffix, 'kind')->acceptsList()->deserializeUsing(self::kinds(...))->build()), WhereIn::make('group', 'kind')->acceptsList()->deserializeUsing(self::kinds(...))->build()],
        );

        self::assertCount(1, $directives);
        self::assertSame(['1', '2'], self::ids((new ArrayFilterHandler())->apply($directives[0]->filter, self::ROWS, $directives[0]->value)));
    }

    #[Test]
    public function aDeserializerReturningNoArrayIsRefused(): void
    {
        // @phpstan-ignore argument.type (the closure must return a list)
        $filter = WhereIn::make('group', 'kind')->deserializeUsing(static fn(array $groups): string => 'k-oak')->build();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The deserializer of filter "group" returned string');

        $filter->comparedValues(['broadleaf']);
    }

    /**
     * @param list<mixed> $groups
     *
     * @return list<string>
     */
    private static function kinds(array $groups): array
    {
        $kinds = [];
        foreach ($groups as $group) {
            \assert(\is_string($group));
            foreach (self::GROUPS[$group] ?? [] as $kind) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * @param mixed $rows
     *
     * @return list<string>
     */
    private static function ids(mixed $rows): array
    {
        self::assertIsArray($rows);

        return \array_values(\array_map(static function (mixed $row): string {
            \assert(\is_array($row) && \is_string($row['id']));

            return $row['id'];
        }, $rows));
    }
}
