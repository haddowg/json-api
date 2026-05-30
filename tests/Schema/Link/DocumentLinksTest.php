<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\ProfileLinkObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class DocumentLinksTest extends TestCase
{
    #[Test]
    public function withoutBaseUri(): void
    {
        self::assertSame('', DocumentLinks::withoutBaseUri()->baseUri);
    }

    #[Test]
    public function withBaseUri(): void
    {
        self::assertSame('https://example.com', DocumentLinks::withBaseUri('https://example.com')->baseUri);
    }

    #[Test]
    public function selfIsNullWhenAbsent(): void
    {
        self::assertNull(DocumentLinks::withoutBaseUri()->self());
    }

    #[Test]
    public function selfReturnsTheSelfLink(): void
    {
        $self = new Link('https://example.com/api/users');

        self::assertSame($self, (new DocumentLinks('', $self))->self());
    }

    #[Test]
    public function relatedReturnsTheRelatedLink(): void
    {
        $related = new Link('https://example.com/api/users');

        self::assertSame($related, (new DocumentLinks('', null, $related))->related());
    }

    #[Test]
    public function paginationLinksAreExposed(): void
    {
        $first = new Link('https://example.com/api/users?page[number]=1');
        $prev = new Link('https://example.com/api/users?page[number]=4');
        $next = new Link('https://example.com/api/users?page[number]=6');
        $last = new Link('https://example.com/api/users?page[number]=10');

        $links = new DocumentLinks('', null, null, $first, $prev, $next, $last);

        self::assertSame($first, $links->first());
        self::assertSame($prev, $links->prev());
        self::assertSame($next, $links->next());
        self::assertSame($last, $links->last());
    }

    #[Test]
    public function paginationLinksAreNullWhenAbsent(): void
    {
        $links = DocumentLinks::withoutBaseUri();

        self::assertNull($links->first());
        self::assertNull($links->prev());
        self::assertNull($links->next());
        self::assertNull($links->last());
    }

    #[Test]
    public function customRelationsArePreserved(): void
    {
        $describedby = new Link('https://example.com/schema');

        $links = new DocumentLinks('', null, null, null, null, null, null, [], ['describedby' => $describedby]);

        self::assertSame($describedby, $links->link('describedby'));
    }

    #[Test]
    public function profilesAreEmptyByDefault(): void
    {
        self::assertSame([], DocumentLinks::withoutBaseUri()->profiles);
    }

    #[Test]
    public function profilesAreDedupedByHref(): void
    {
        $profile = new ProfileLinkObject('https://example.com/profiles/timestamps');

        $links = new DocumentLinks('', null, null, null, null, null, null, [$profile, $profile]);

        self::assertCount(1, $links->profiles);
        self::assertSame($profile, $links->profiles[0]);
    }

    #[Test]
    public function transformPrependsBaseUri(): void
    {
        $links = new DocumentLinks(
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

    #[Test]
    public function transformEmitsProfileLinks(): void
    {
        $links = new DocumentLinks(
            '',
            new Link('https://example.com/articles'),
            null,
            null,
            null,
            null,
            null,
            [
                new ProfileLinkObject('https://example.com/profiles/a'),
                new ProfileLinkObject('https://example.com/profiles/b'),
            ],
        );

        $transformed = $links->transform();

        self::assertArrayHasKey('self', $transformed);
        self::assertArrayHasKey('profile', $transformed);
        self::assertIsArray($transformed['profile']);
        self::assertCount(2, $transformed['profile']);
    }

    #[Test]
    public function transformOmitsProfileWhenEmpty(): void
    {
        $transformed = (new DocumentLinks('', new Link('https://example.com/articles')))->transform();

        self::assertArrayNotHasKey('profile', $transformed);
    }
}
