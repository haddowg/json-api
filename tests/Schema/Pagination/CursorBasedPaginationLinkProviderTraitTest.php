<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Pagination;

use haddowg\JsonApi\Tests\Double\StubCursorBasedPaginationProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CursorBasedPaginationLinkProviderTraitTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenCurrentItemIsNull(): void
    {
        $provider = $this->createProvider(0, 0, null, 0, 0, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users?', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenOnlyPathProvided(): void
    {
        $provider = $this->createProvider(0, 0, 2, 0, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bcursor%5D=2&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenQueryStringSeparatorIsProvided(): void
    {
        $provider = $this->createProvider(0, 0, 2, 0, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bcursor%5D=2&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(0, 0, 2, 0, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?a=b', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?a=b&page%5Bcursor%5D=2&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathAndAdditionalQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(0, 0, 2, 0, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?a=b', 'a=c&b=d');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?a=c&b=d&page%5Bcursor%5D=2&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathAndAdditionalPaginationQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(0, 0, 2, 0, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users', 'page[cursor]=0');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bcursor%5D=2&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getFirstLinkWhenFirstItemIsNull(): void
    {
        $provider = $this->createProvider(null, 0, 0, 0, 0, 10);

        self::assertNull($provider->getFirstLink('https://example.com/api/users?', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getFirstLink(): void
    {
        $provider = $this->createProvider(0, 0, 0, 0, 0, 10);

        $link = $provider->getFirstLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bcursor%5D=0&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getLastLinkWhenLastItemIsNull(): void
    {
        $provider = $this->createProvider(0, null, 0, 0, 0, 10);

        self::assertNull($provider->getLastLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getLastLink(): void
    {
        $provider = $this->createProvider(0, 4, 0, 0, 0, 10);

        $link = $provider->getLastLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bcursor%5D=4&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLink(): void
    {
        $provider = $this->createProvider(0, 0, 0, 2, 0, 10);

        $link = $provider->getPrevLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bcursor%5D=2&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getNextLink(): void
    {
        $provider = $this->createProvider(0, 0, 0, 0, 3, 10);

        $link = $provider->getNextLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bcursor%5D=3&page%5Bsize%5D=10', $link->href);
    }

    private function createProvider(
        mixed $firstItem,
        mixed $lastItem,
        mixed $currentItem,
        mixed $previousItem,
        mixed $nextItem,
        int $size,
    ): StubCursorBasedPaginationProvider {
        return new StubCursorBasedPaginationProvider($firstItem, $lastItem, $currentItem, $previousItem, $nextItem, $size);
    }
}
