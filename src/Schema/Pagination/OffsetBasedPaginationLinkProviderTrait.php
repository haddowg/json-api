<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Pagination;

use haddowg\JsonApi\Request\Pagination\OffsetBasedPagination;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Transformer\Utils;

// TODO(phase-2): folds into Page value object; this trait and PaginationLinkProviderInterface are slated for deletion then.

/**
 * Provides JSON:API pagination links for offset + limit pagination.
 *
 * The consuming class must implement {@see getTotalItems()}, {@see getOffset()},
 * and {@see getLimit()}.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
trait OffsetBasedPaginationLinkProviderTrait
{
    abstract protected function getTotalItems(): int;

    abstract protected function getOffset(): int;

    abstract protected function getLimit(): int;

    public function getSelfLink(string $uri, string $queryString): ?Link
    {
        $offset = $this->getOffset();

        if ($offset < 0 || $offset >= $this->getTotalItems()) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getOffset(), $this->getLimit());
    }

    public function getFirstLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, 0, $this->getLimit());
    }

    public function getLastLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, \max($this->getTotalItems() - $this->getLimit(), 0), $this->getLimit());
    }

    public function getPrevLink(string $uri, string $queryString): ?Link
    {
        if ($this->getOffset() <= 0 || $this->getOffset() + $this->getLimit() > $this->getTotalItems()) {
            return null;
        }

        $prevOffset = $this->getOffset() - $this->getLimit() > 0 ? $this->getOffset() - $this->getLimit() : 0;

        return $this->createPaginatedLink($uri, $queryString, $prevOffset, $this->getLimit());
    }

    public function getNextLink(string $uri, string $queryString): ?Link
    {
        if ($this->getOffset() < 0 || $this->getOffset() + $this->getLimit() >= $this->getTotalItems()) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getOffset() + $this->getLimit(), $this->getLimit());
    }

    protected function createPaginatedLink(string $uri, string $queryString, int $offset, int $limit): ?Link
    {
        if ($this->getTotalItems() <= 0 || $this->getLimit() <= 0) {
            return null;
        }

        return new Link(
            Utils::getUri($uri, $queryString, OffsetBasedPagination::getPaginationQueryParams($offset, $limit)),
        );
    }
}
