<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\ErrorLinks;
use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class ErrorLinksTest extends TestCase
{
    #[Test]
    public function withoutBaseUri(): void
    {
        self::assertSame('', ErrorLinks::withoutBaseUri()->baseUri);
    }

    #[Test]
    public function withBaseUri(): void
    {
        self::assertSame('https://example.com', ErrorLinks::withBaseUri('https://example.com')->baseUri);
    }

    #[Test]
    public function aboutIsNullWhenAbsent(): void
    {
        self::assertNull(ErrorLinks::withoutBaseUri()->about());
    }

    #[Test]
    public function aboutReturnsTheAboutLink(): void
    {
        $about = new Link('https://example.com/about');

        $links = new ErrorLinks('', $about);

        self::assertSame($about, $links->about());
    }

    #[Test]
    public function typesAreEmptyByDefault(): void
    {
        self::assertSame([], ErrorLinks::withoutBaseUri()->types);
    }

    #[Test]
    public function typesAreDedupedByHref(): void
    {
        $type = new Link('https://example.com/errors/404');

        $links = new ErrorLinks('', null, [$type, $type]);

        self::assertCount(1, $links->types);
        self::assertSame($type, $links->types[0]);
    }

    #[Test]
    public function transformEmitsAboutAndTypeLinks(): void
    {
        $links = new ErrorLinks(
            '',
            new Link('https://example.com/api/errors/1'),
            [
                new Link('https://example.com/api/errors/type/1'),
                new Link('https://example.com/api/errors/type/2'),
            ],
        );

        $transformed = $links->transform();

        self::assertArrayHasKey('about', $transformed);
        self::assertArrayHasKey('type', $transformed);
        self::assertIsArray($transformed['type']);
        self::assertCount(2, $transformed['type']);
    }
}
