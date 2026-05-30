<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\RelationshipLinks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
#[Group('spec:fetching-relationships')]
final class RelationshipLinksTest extends TestCase
{
    #[Test]
    public function withoutBaseUri(): void
    {
        self::assertSame('', RelationshipLinks::withoutBaseUri()->baseUri);
    }

    #[Test]
    public function withBaseUri(): void
    {
        self::assertSame('https://example.com', RelationshipLinks::withBaseUri('https://example.com')->baseUri);
    }

    #[Test]
    public function selfIsNullWhenAbsent(): void
    {
        self::assertNull(RelationshipLinks::withoutBaseUri()->self());
    }

    #[Test]
    public function selfReturnsTheSelfLink(): void
    {
        $self = new Link('https://example.com/api/users');

        $links = new RelationshipLinks('', $self);

        self::assertSame($self, $links->self());
        self::assertSame($self, $links->link('self'));
    }

    #[Test]
    public function relatedIsNullWhenAbsent(): void
    {
        self::assertNull(RelationshipLinks::withoutBaseUri()->related());
    }

    #[Test]
    public function relatedReturnsTheRelatedLink(): void
    {
        $related = new Link('https://example.com/articles/1/author');

        $links = new RelationshipLinks('', null, $related);

        self::assertSame($related, $links->related());
    }

    #[Test]
    public function customRelationsArePreserved(): void
    {
        $describedby = new Link('https://example.com/schema');

        $links = new RelationshipLinks('', null, null, ['describedby' => $describedby]);

        self::assertSame($describedby, $links->link('describedby'));
    }

    #[Test]
    public function transformPrependsBaseUri(): void
    {
        $links = new RelationshipLinks(
            'https://example.com',
            new Link('/articles/1/relationships/author'),
            new Link('/articles/1/author'),
        );

        self::assertSame(
            [
                'self' => 'https://example.com/articles/1/relationships/author',
                'related' => 'https://example.com/articles/1/author',
            ],
            $links->transform(),
        );
    }
}
