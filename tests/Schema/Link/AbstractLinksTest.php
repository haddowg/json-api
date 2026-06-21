<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Link;

use haddowg\JsonApi\Schema\Link\AbstractLinks;
use haddowg\JsonApi\Schema\Link\Link;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class AbstractLinksTest extends TestCase
{
    #[Test]
    public function exposesBaseUri(): void
    {
        $links = $this->createLinks('https://example.com');

        self::assertSame('https://example.com', $links->baseUri);
    }

    #[Test]
    public function linkReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->createLinks()->link('self'));
    }

    #[Test]
    public function linkReturnsTheRegisteredLink(): void
    {
        $self = new Link('/users');

        $links = $this->createLinks('', ['self' => $self]);

        self::assertSame($self, $links->link('self'));
    }

    #[Test]
    public function nullLinksAreDropped(): void
    {
        $links = $this->createLinks('', ['self' => new Link('/users'), 'related' => null]);

        self::assertNull($links->link('related'));
        self::assertSame(['self' => '/users'], $links->transform());
    }

    #[Test]
    public function transformPrependsBaseUriToEachLink(): void
    {
        $links = $this->createLinks('https://example.com', [
            'self' => new Link('/users'),
            'related' => new Link('/people'),
        ]);

        self::assertSame(
            [
                'self' => 'https://example.com/users',
                'related' => 'https://example.com/people',
            ],
            $links->transform(),
        );
    }

    /**
     * @param array<string, Link|null> $links
     */
    private function createLinks(string $baseUri = '', array $links = []): AbstractLinks
    {
        return new readonly class ($baseUri, $links) extends AbstractLinks {
            protected function reboundTo(string $baseUri): static
            {
                return new self($baseUri, $this->links);
            }
        };
    }
}
