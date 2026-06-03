<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Profile\ProfileInterface;
use haddowg\JsonApi\Transformer\Utils;

/**
 * Base for the strategy-specific {@see Page} value objects.
 *
 * Holds the page's items and exposes them via {@see getIterator()} so a page is
 * directly iterable. Provides a {@see paginatedLink()} helper that builds an
 * absolute link, merging the strategy's `page[…]` parameters over the request's
 * existing query string (so unrelated params — `filter`, `sort`, sparse
 * fieldsets — are preserved across pages). Defaults {@see profile()} to `null`
 * and {@see pageMeta()} to `[]`; subtypes override what they need.
 *
 * @template T
 *
 * @implements \haddowg\JsonApi\Pagination\PageInterface<T>
 */
abstract readonly class AbstractPage implements \haddowg\JsonApi\Pagination\PageInterface
{
    /**
     * @param iterable<T> $items
     */
    public function __construct(protected iterable $items) {}

    /**
     * @return \Traversable<int, T>
     */
    public function getIterator(): \Traversable
    {
        $index = 0;
        foreach ($this->items as $item) {
            yield $index++ => $item;
        }
    }

    public function pageMeta(): array
    {
        return [];
    }

    public function profile(): ?ProfileInterface
    {
        return null;
    }

    /**
     * Builds an absolute pagination link by merging `$params` over `$uri`'s own
     * query string and the request's `$queryString`.
     *
     * @param array<string, mixed> $params
     */
    protected function paginatedLink(string $uri, string $queryString, array $params): Link
    {
        return new Link(Utils::getUri($uri, $queryString, $params));
    }
}
