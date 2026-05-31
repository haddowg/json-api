<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Pagination;

use haddowg\JsonApi\Request\Pagination\FixedPageBasedPagination;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Transformer\Utils;

// TODO(phase-2): folds into Page value object; this trait and PaginationLinkProviderInterface are slated for deletion then.

/**
 * Provides JSON:API pagination links for fixed-size page-number pagination
 * (page number only; the page size is server-determined and not echoed in links).
 *
 * The consuming class must implement {@see getTotalItems()}, {@see getPage()},
 * and {@see getSize()}.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
trait FixedPageBasedPaginationLinkProviderTrait
{
    abstract public function getTotalItems(): int;

    abstract public function getPage(): int;

    abstract public function getSize(): int;

    public function getSelfLink(string $uri, string $queryString): ?Link
    {
        if ($this->getPage() <= 0 || $this->getSize() <= 0 || $this->getPage() > $this->getLastPage()) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getPage());
    }

    public function getFirstLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, 1);
    }

    public function getLastLink(string $uri, string $queryString): ?Link
    {
        if ($this->getSize() <= 0) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getLastPage());
    }

    public function getPrevLink(string $uri, string $queryString): ?Link
    {
        if ($this->getPage() <= 1 || $this->getSize() <= 0) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getPage() - 1);
    }

    public function getNextLink(string $uri, string $queryString): ?Link
    {
        if ($this->getPage() <= 0 || $this->getSize() <= 0 || $this->getPage() >= $this->getLastPage()) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getPage() + 1);
    }

    protected function createPaginatedLink(string $uri, string $queryString, int $page): ?Link
    {
        if ($this->getTotalItems() <= 0 || $this->getSize() <= 0) {
            return null;
        }

        return new Link(
            Utils::getUri($uri, $queryString, FixedPageBasedPagination::getPaginationQueryParams($page)),
        );
    }

    protected function getLastPage(): int
    {
        return (int) \ceil($this->getTotalItems() / $this->getSize());
    }
}
