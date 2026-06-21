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

    #[Test]
    #[Group('spec:document-structure')]
    public function transformLeavesAnAbsoluteHrefUntouchedEvenWithABase(): void
    {
        // An already-absolute href (a scheme-qualified URL) is never prefixed by the
        // base — so an author-supplied documentation URL is not corrupted.
        $link = new Link('https://docs.example/errors/404');

        self::assertSame('https://docs.example/errors/404', $link->transform('https://api.example'));
    }

    #[Test]
    #[Group('spec:document-structure')]
    public function transformLeavesAProtocolRelativeHrefUntouchedEvenWithABase(): void
    {
        $link = new Link('//cdn.example/asset.png');

        self::assertSame('//cdn.example/asset.png', $link->transform('https://api.example'));
    }
}
