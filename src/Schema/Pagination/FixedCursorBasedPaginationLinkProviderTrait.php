<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Pagination;

use haddowg\JsonApi\Request\Pagination\FixedCursorBasedPagination;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Transformer\Utils;

// TODO(phase-2): folds into Page value object; this trait and PaginationLinkProviderInterface are slated for deletion then.

/**
 * Provides JSON:API pagination links for fixed-size cursor-based pagination
 * (cursor only; the page size is server-determined and not echoed in links).
 *
 * The consuming class must implement {@see getFirstItem()}, {@see getLastItem()},
 * {@see getCurrentItem()}, {@see getPreviousItem()}, and {@see getNextItem()}.
 * Each cursor method returns `mixed`; a `null` cursor means no link is emitted.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
trait FixedCursorBasedPaginationLinkProviderTrait
{
    abstract public function getFirstItem(): mixed;

    abstract public function getLastItem(): mixed;

    abstract public function getCurrentItem(): mixed;

    abstract public function getPreviousItem(): mixed;

    abstract public function getNextItem(): mixed;

    public function getSelfLink(string $uri, string $queryString): ?Link
    {
        if ($this->getCurrentItem() === null) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getCurrentItem());
    }

    public function getFirstLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getFirstItem());
    }

    public function getLastLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getLastItem());
    }

    public function getPrevLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getPreviousItem());
    }

    public function getNextLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getNextItem());
    }

    protected function createPaginatedLink(string $uri, string $queryString, mixed $cursor): ?Link
    {
        if ($cursor === null) {
            return null;
        }

        return new Link(
            Utils::getUri($uri, $queryString, FixedCursorBasedPagination::getPaginationQueryParams($cursor)),
        );
    }
}
