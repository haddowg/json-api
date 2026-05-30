<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\LinkObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkObjectTest extends TestCase
{
    #[Test]
    #[Group('spec:document-structure')]
    public function exposesHrefAndMeta(): void
    {
        $linkObject = new LinkObject('https://example.com/api/users', meta: ['abc' => 'def']);

        self::assertSame('https://example.com/api/users', $linkObject->href);
        self::assertSame(['abc' => 'def'], $linkObject->meta);
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformWithHrefOnly(): void
    {
        $linkObject = new LinkObject('https://example.com/api/users');

        self::assertSame(
            ['href' => 'https://example.com/api/users'],
            $linkObject->transform(''),
        );
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformWithMeta(): void
    {
        $linkObject = new LinkObject('https://example.com/api/users', meta: ['abc' => 'def']);

        self::assertSame(
            [
                'href' => 'https://example.com/api/users',
                'meta' => ['abc' => 'def'],
            ],
            $linkObject->transform(''),
        );
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformPrependsBaseUri(): void
    {
        $linkObject = new LinkObject('/api/users');

        self::assertSame(
            ['href' => 'https://example.com/api/users'],
            $linkObject->transform('https://example.com'),
        );
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformIncludesAllSpecMembers(): void
    {
        $linkObject = new LinkObject(
            href: 'https://example.com/api/users',
            rel: 'related',
            title: 'Users',
            type: 'application/vnd.api+json',
            hreflang: 'en',
            meta: ['abc' => 'def'],
        );

        self::assertSame(
            [
                'href' => 'https://example.com/api/users',
                'rel' => 'related',
                'title' => 'Users',
                'type' => 'application/vnd.api+json',
                'hreflang' => 'en',
                'meta' => ['abc' => 'def'],
            ],
            $linkObject->transform(''),
        );
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformOmitsEmptyOptionalMembers(): void
    {
        $linkObject = new LinkObject('https://example.com/api/users', rel: 'self');

        self::assertSame(
            [
                'href' => 'https://example.com/api/users',
                'rel' => 'self',
            ],
            $linkObject->transform(''),
        );
    }
}
