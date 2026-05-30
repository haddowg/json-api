<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class ResourceLinksTest extends TestCase
{
    #[Test]
    public function withoutBaseUri(): void
    {
        self::assertSame('', ResourceLinks::withoutBaseUri()->baseUri);
    }

    #[Test]
    public function withBaseUri(): void
    {
        self::assertSame('https://example.com', ResourceLinks::withBaseUri('https://example.com')->baseUri);
    }

    #[Test]
    public function selfIsNullWhenAbsent(): void
    {
        self::assertNull(ResourceLinks::withoutBaseUri()->self());
    }

    #[Test]
    public function selfReturnsTheSelfLink(): void
    {
        $self = new Link('https://example.com/api/users');

        $links = new ResourceLinks('', $self);

        self::assertSame($self, $links->self());
        self::assertSame($self, $links->link('self'));
    }

    #[Test]
    public function customRelationsArePreserved(): void
    {
        $describedby = new Link('https://example.com/schema');

        $links = new ResourceLinks('', null, ['describedby' => $describedby]);

        self::assertSame($describedby, $links->link('describedby'));
    }

    #[Test]
    public function transformPrependsBaseUri(): void
    {
        $links = new ResourceLinks('https://example.com', new Link('/articles/1'));

        self::assertSame(['self' => 'https://example.com/articles/1'], $links->transform());
    }
}
