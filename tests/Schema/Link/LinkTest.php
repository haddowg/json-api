<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkTest extends TestCase
{
    #[Test]
    #[Group('spec:document-structure')]
    public function exposesHref(): void
    {
        $link = new Link('https://example.com/api/users');

        self::assertSame('https://example.com/api/users', $link->href);
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformReturnsBareString(): void
    {
        $link = new Link('https://example.com/api/users');

        self::assertSame('https://example.com/api/users', $link->transform(''));
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformPrependsBaseUri(): void
    {
        $link = new Link('/api/users');

        self::assertSame('https://example.com/api/users', $link->transform('https://example.com'));
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformPreservesUriTemplate(): void
    {
        // JSON:API 1.1 has no `templated` member; a template is just an href.
        $link = new Link('https://example.com/api/users{/id}');

        self::assertSame('https://example.com/api/users{/id}', $link->transform(''));
    }
}
