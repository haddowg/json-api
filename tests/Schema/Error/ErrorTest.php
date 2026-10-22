<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Error;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApi\Schema\Link\ErrorLinks;
use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class ErrorTest extends TestCase
{
    #[Test]
    public function exposesItsMembers(): void
    {
        $source = new ErrorSource('/data/attributes/name', 'name');
        $links = new ErrorLinks();

        $error = new Error(
            id: '123456789',
            status: '500',
            code: 'UNKNOWN_ERROR',
            title: 'Unknown error!',
            detail: 'An unknown error has happened.',
            source: $source,
            links: $links,
            meta: ['abc' => 'def'],
        );

        self::assertSame('123456789', $error->id);
        self::assertSame('500', $error->status);
        self::assertSame('UNKNOWN_ERROR', $error->code);
        self::assertSame('Unknown error!', $error->title);
        self::assertSame('An unknown error has happened.', $error->detail);
        self::assertSame($source, $error->source);
        self::assertSame($links, $error->links);
        self::assertSame(['abc' => 'def'], $error->meta);
    }

    #[Test]
    public function defaultsToEmptyMembers(): void
    {
        $error = new Error();

        self::assertSame('', $error->id);
        self::assertNull($error->source);
        self::assertNull($error->links);
        self::assertSame([], $error->meta);
        self::assertSame([], $error->transform());
    }

    #[Test]
    public function transformOmitsEmptyMembers(): void
    {
        $error = new Error(
            id: '123456789',
            status: '500',
            code: 'UNKNOWN_ERROR',
            title: 'Unknown error!',
            detail: 'An unknown error has happened.',
        );

        self::assertSame(
            [
                'id' => '123456789',
                'status' => '500',
                'code' => 'UNKNOWN_ERROR',
                'title' => 'Unknown error!',
                'detail' => 'An unknown error has happened.',
            ],
            $error->transform(),
        );
    }

    #[Test]
    public function transformIncludesStructuredMembers(): void
    {
        $error = new Error(
            id: '123456789',
            status: '500',
            code: 'UNKNOWN_ERROR',
            title: 'Unknown error!',
            detail: 'An unknown error has happened.',
            source: ErrorSource::fromPointer('/data/attributes/name'),
            links: new ErrorLinks(about: new Link('https://example.com/errors/unknown')),
            meta: ['abc' => 'def'],
        );

        self::assertSame(
            [
                'id' => '123456789',
                'meta' => ['abc' => 'def'],
                'links' => ['about' => 'https://example.com/errors/unknown'],
                'status' => '500',
                'code' => 'UNKNOWN_ERROR',
                'title' => 'Unknown error!',
                'detail' => 'An unknown error has happened.',
                'source' => ['pointer' => '/data/attributes/name'],
            ],
            $error->transform(),
        );
    }

    #[Test]
    public function transformOmitsAnEmptySourceAndEmptyLinks(): void
    {
        // Both are JSON objects; rendered empty they would encode as `[]`.
        $error = new Error(status: '500', source: new ErrorSource('', ''), links: new ErrorLinks());

        self::assertSame(['status' => '500'], $error->transform());
    }
}
