<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Pagination;

use haddowg\JsonApi\Tests\Double\StubOffsetBasedPaginationProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OffsetBasedPaginationLinkProviderTraitTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenOffsetIsNegative(): void
    {
        $provider = $this->createProvider(10, -6, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenOffsetIsZero(): void
    {
        $provider = $this->createProvider(10, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenLimitIsNegative(): void
    {
        $provider = $this->createProvider(10, 0, -1);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenLimitIsZero(): void
    {
        $provider = $this->createProvider(10, 1, 0);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenTotalItemsIsNegative(): void
    {
        $provider = $this->createProvider(-30, 1, 0);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenTotalItemsIsZero(): void
    {
        $provider = $this->createProvider(0, 0, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenOffsetIsTooMuch(): void
    {
        $provider = $this->createProvider(30, 30, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenOnlyPathIsProvided(): void
    {
        $provider = $this->createProvider(10, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathWithQueryStringSeparatorIsProvided(): void
    {
        $provider = $this->createProvider(10, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathWithQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(10, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?a=b', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?a=b&page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathAndAdditionalQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(10, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?a=b', 'a=c&b=d');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?a=c&b=d&page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathAndAdditionalPaginationQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(10, 0, 10);

        $link = $provider->getSelfLink('https://example.com/api/users', 'page[offset]=0&page[limit]=0');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getFirstLinkWhenTotalItemsIsZero(): void
    {
        $provider = $this->createProvider(0, 2, 10);

        self::assertNull($provider->getFirstLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getFirstLinkWhenLimitIsZero(): void
    {
        $provider = $this->createProvider(10, 0, 0);

        self::assertNull($provider->getFirstLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getFirstLink(): void
    {
        $provider = $this->createProvider(10, 2, 10);

        $link = $provider->getFirstLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getLastLink(): void
    {
        $provider = $this->createProvider(50, 2, 10);

        $link = $provider->getLastLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=40&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getLastLinkWhenQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(50, 2, 10);

        $link = $provider->getLastLink('https://example.com/api/users?a=b', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?a=b&page%5Boffset%5D=40&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLinkWhenOffsetIsZero(): void
    {
        $provider = $this->createProvider(50, 0, 10);

        self::assertNull($provider->getPrevLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLinkWhenPageIsTruncated(): void
    {
        $provider = $this->createProvider(50, 9, 10);

        $link = $provider->getPrevLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLink(): void
    {
        $provider = $this->createProvider(50, 10, 10);

        $link = $provider->getPrevLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=0&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLinkWhenOffsetIsMoreThanLimit(): void
    {
        $provider = $this->createProvider(50, 16, 10);

        $link = $provider->getPrevLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=6&page%5Blimit%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getNextLinkWhenOffsetIsLast(): void
    {
        $provider = $this->createProvider(50, 41, 10);

        self::assertNull($provider->getNextLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getNextLink(): void
    {
        $provider = $this->createProvider(50, 10, 10);

        $link = $provider->getNextLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Boffset%5D=20&page%5Blimit%5D=10', $link->href);
    }

    private function createProvider(int $totalItems, int $offset, int $limit): StubOffsetBasedPaginationProvider
    {
        return new StubOffsetBasedPaginationProvider($totalItems, $offset, $limit);
    }
}
