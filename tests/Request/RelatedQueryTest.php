<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request;

use haddowg\JsonApi\Request\RelatedQuery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:fetching-data')]
final class RelatedQueryTest extends TestCase
{
    #[Test]
    public function defaultIsEmpty(): void
    {
        $query = new RelatedQuery();

        self::assertTrue($query->isEmpty());
        self::assertNull($query->sort);
        self::assertSame([], $query->filter);
        self::assertSame([], $query->sortFields());
        self::assertSame('', $query->toPlainQueryString());
    }

    #[Test]
    public function aSortAloneIsNotEmpty(): void
    {
        $query = new RelatedQuery('-duration,title');

        self::assertFalse($query->isEmpty());
        self::assertSame(['-duration', 'title'], $query->sortFields());
    }

    #[Test]
    public function aFilterAloneIsNotEmpty(): void
    {
        $query = new RelatedQuery(null, ['longerThan' => '300']);

        self::assertFalse($query->isEmpty());
        self::assertSame([], $query->sortFields());
    }

    #[Test]
    public function sortFieldsTrimAndDropEmptyClauses(): void
    {
        $query = new RelatedQuery(' -duration , , title ');

        self::assertSame(['-duration', 'title'], $query->sortFields());
    }

    #[Test]
    public function plainQueryStringTranslatesSortAndFilterToTheSpecGrammar(): void
    {
        $query = new RelatedQuery('-duration', ['longerThan' => '300']);

        $parsed = [];
        \parse_str($query->toPlainQueryString(), $parsed);

        self::assertSame('-duration', $parsed['sort']);
        self::assertSame(['longerThan' => '300'], $parsed['filter']);
    }

    #[Test]
    public function plainQueryStringOmitsAnAbsentSortOrFilter(): void
    {
        self::assertSame('sort=-duration', \urldecode((new RelatedQuery('-duration'))->toPlainQueryString()));
        self::assertSame('filter%5Bk%5D=v', (new RelatedQuery(null, ['k' => 'v']))->toPlainQueryString());
    }
}
