<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ArrayFilterHandler::class)]
final class ListFilterHandlerTest extends TestCase
{
    private const array ROWS = [
        ['id' => '1', 'column' => 'a,b'],
        ['id' => '2', 'column' => 'a'],
        ['id' => '3', 'column' => 'b'],
    ];

    #[Test]
    public function anArrayOnlyListKeepsACommaInsideAMember(): void
    {
        $handler = new ArrayFilterHandler();

        self::assertSame([self::ROWS[0]], $handler->apply(WhereIn::make('column')->acceptsList(delimited: false)->build(), self::ROWS, ['a,b']));
        self::assertSame([self::ROWS[0]], $handler->apply(WhereIn::make('column')->acceptsList(delimited: false)->build(), self::ROWS, 'a,b'));
        self::assertSame([self::ROWS[1], self::ROWS[2]], $handler->apply(WhereNotIn::make('column')->acceptsList(delimited: false)->build(), self::ROWS, ['a,b']));
    }

    #[Test]
    public function aDelimitedListStillSplitsAString(): void
    {
        $handler = new ArrayFilterHandler();

        self::assertSame([self::ROWS[1], self::ROWS[2]], $handler->apply(WhereIn::make('column')->acceptsList()->build(), self::ROWS, 'a, b'));
        self::assertSame([self::ROWS[0], self::ROWS[2]], $handler->apply(WhereIdIn::make()->acceptsList()->build(), self::ROWS, ['1', '3']));
    }
}
