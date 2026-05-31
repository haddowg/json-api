<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Pagination;

use haddowg\JsonApi\Request\Pagination\CursorBasedPagination;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Transformer\Utils;

// TODO(phase-2): folds into Page value object; this trait and PaginationLinkProviderInterface are slated for deletion then.

/**
 * Provides JSON:API pagination links for cursor + size pagination.
 *
 * The consuming class must implement {@see getFirstItem()}, {@see getLastItem()},
 * {@see getCurrentItem()}, {@see getPreviousItem()}, {@see getNextItem()}, and
 * {@see getSize()}.  Each cursor method returns `mixed`; a `null` cursor means
 * no link is emitted for that direction.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
trait CursorBasedPaginationLinkProviderTrait
{
    abstract public function getFirstItem(): mixed;

    abstract public function getLastItem(): mixed;

    abstract public function getCurrentItem(): mixed;

    abstract public function getPreviousItem(): mixed;

    abstract public function getNextItem(): mixed;

    abstract public function getSize(): int;

    public function getSelfLink(string $uri, string $queryString): ?Link
    {
        if ($this->getCurrentItem() === null) {
            return null;
        }

        return $this->createPaginatedLink($uri, $queryString, $this->getCurrentItem(), $this->getSize());
    }

    public function getFirstLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getFirstItem(), $this->getSize());
    }

    public function getLastLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getLastItem(), $this->getSize());
    }

    public function getPrevLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getPreviousItem(), $this->getSize());
    }

    public function getNextLink(string $uri, string $queryString): ?Link
    {
        return $this->createPaginatedLink($uri, $queryString, $this->getNextItem(), $this->getSize());
    }

    protected function createPaginatedLink(string $uri, string $queryString, mixed $cursor, int $size): ?Link
    {
        if ($cursor === null) {
            return null;
        }

        return new Link(
            Utils::getUri($uri, $queryString, CursorBasedPagination::getPaginationQueryParams($cursor, $size)),
        );
    }
}
