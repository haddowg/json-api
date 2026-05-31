<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\LinkObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class LinkObjectDescribedbyTest extends TestCase
{
    #[Test]
    public function emitsDescribedbyAsTheNestedLink(): void
    {
        $describedby = new Link('https://example.com/schemas/article');

        $linkObject = new LinkObject(
            href: 'https://example.com/articles',
            describedby: $describedby,
        );

        $transformed = $linkObject->transform('');

        self::assertArrayHasKey('describedby', $transformed);
        self::assertSame($describedby->transform(''), $transformed['describedby']);
    }

    #[Test]
    public function omitsDescribedbyWhenAbsent(): void
    {
        $transformed = (new LinkObject(href: 'https://example.com/articles'))->transform('');

        self::assertArrayNotHasKey('describedby', $transformed);
    }
}
