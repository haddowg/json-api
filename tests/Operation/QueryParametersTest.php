<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Operation;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:fetching-data')]
final class QueryParametersTest extends TestCase
{
    #[Test]
    public function fromRequestExtractsEveryQueryParamGroup(): void
    {
        $request = StubJsonApiRequest::create([
            'fields' => [
                'articles' => 'title,body',
                'people' => 'name',
            ],
            'include' => 'author,comments.author',
            'sort' => '-created,title',
            'filter' => ['published' => 'true'],
            'page' => ['number' => '2', 'size' => '10'],
        ]);

        $query = QueryParameters::fromRequest($request);

        self::assertSame(
            ['articles' => ['title', 'body'], 'people' => ['name']],
            $query->fields,
        );
        self::assertSame(['author', 'comments.author'], $query->includes);
        self::assertSame(['-created', 'title'], $query->sort);
        self::assertSame(['published' => 'true'], $query->filter);
        self::assertSame(['number' => '2', 'size' => '10'], $query->pagination);
    }

    #[Test]
    public function fromRequestYieldsEmptyGroupsWhenNoQueryParamsPresent(): void
    {
        $query = QueryParameters::fromRequest(StubJsonApiRequest::create());

        self::assertSame([], $query->fields);
        self::assertSame([], $query->includes);
        self::assertSame([], $query->sort);
        self::assertSame([], $query->filter);
        self::assertSame([], $query->pagination);
    }

    #[Test]
    public function fromRequestTrimsAndDropsEmptyFieldAndIncludeEntries(): void
    {
        $request = StubJsonApiRequest::create([
            'fields' => ['articles' => 'title, body, '],
            'include' => 'author, , comments',
        ]);

        $query = QueryParameters::fromRequest($request);

        self::assertSame(['articles' => ['title', 'body']], $query->fields);
        self::assertSame(['author', 'comments'], $query->includes);
    }

    #[Test]
    public function isReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(QueryParameters::class))->isReadOnly());
    }
}
