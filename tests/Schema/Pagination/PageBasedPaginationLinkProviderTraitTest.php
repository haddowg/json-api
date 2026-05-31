<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Pagination;

use haddowg\JsonApi\Tests\Double\StubPageBasedPaginationProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageBasedPaginationLinkProviderTraitTest extends TestCase
{
    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPageIsNegative(): void
    {
        $provider = $this->createProvider(10, -6, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPageIsZero(): void
    {
        $provider = $this->createProvider(10, 0, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenSizeIsNegative(): void
    {
        $provider = $this->createProvider(10, 1, -1);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenSizeIsZero(): void
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
        $provider = $this->createProvider(0, 1, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPageIsMoreThanLastPage(): void
    {
        $provider = $this->createProvider(30, 31, 10);

        self::assertNull($provider->getSelfLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathIsProvided(): void
    {
        $provider = $this->createProvider(10, 1, 10);

        $link = $provider->getSelfLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=1&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathWithQueryStringSeparatorIsProvided(): void
    {
        $provider = $this->createProvider(10, 1, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=1&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathAndAdditionalQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(10, 1, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?a=b', 'a=c&b=d');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?a=c&b=d&page%5Bnumber%5D=1&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathAndAdditionalPaginationQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(10, 1, 10);

        $link = $provider->getSelfLink('https://example.com/api/users', 'page[number]=0&page[size]=0');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=1&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getSelfLinkWhenPathWithQueryStringIsProvided(): void
    {
        $provider = $this->createProvider(10, 1, 10);

        $link = $provider->getSelfLink('https://example.com/api/users?a=b', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?a=b&page%5Bnumber%5D=1&page%5Bsize%5D=10', $link->href);
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
    public function getFirstLinkWhenSizeIsZero(): void
    {
        $provider = $this->createProvider(10, 2, 0);

        self::assertNull($provider->getFirstLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getFirstLink(): void
    {
        $provider = $this->createProvider(10, 2, 10);

        $link = $provider->getFirstLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=1&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getLastLinkWhenTotalItemsIsZero(): void
    {
        $provider = $this->createProvider(0, 2, 10);

        self::assertNull($provider->getLastLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getLastLinkWhenSizeIsZero(): void
    {
        $provider = $this->createProvider(50, 2, 0);

        self::assertNull($provider->getLastLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getLastLink(): void
    {
        $provider = $this->createProvider(50, 2, 10);

        $link = $provider->getLastLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=5&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLinkWhenPageIsFirst(): void
    {
        $provider = $this->createProvider(50, 1, 10);

        self::assertNull($provider->getPrevLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLinkWhenPageIsLast(): void
    {
        $provider = $this->createProvider(50, 5, 10);

        $link = $provider->getPrevLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=4&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPrevLink(): void
    {
        $provider = $this->createProvider(50, 2, 10);

        $link = $provider->getPrevLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=1&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getNextLinkWhenPageIsLast(): void
    {
        $provider = $this->createProvider(50, 5, 10);

        self::assertNull($provider->getNextLink('https://example.com/api/users', ''));
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getNextLinkWhenPageIsBeforeLast(): void
    {
        $provider = $this->createProvider(50, 4, 10);

        $link = $provider->getNextLink('https://example.com/api/users', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=5&page%5Bsize%5D=10', $link->href);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getNextLink(): void
    {
        $provider = $this->createProvider(50, 2, 10);

        $link = $provider->getNextLink('https://example.com/api/users?', '');

        self::assertNotNull($link);
        self::assertSame('https://example.com/api/users?page%5Bnumber%5D=3&page%5Bsize%5D=10', $link->href);
    }

    private function createProvider(int $totalItems, int $page, int $size): StubPageBasedPaginationProvider
    {
        return new StubPageBasedPaginationProvider($totalItems, $page, $size);
    }
}
