<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Pagination;

use haddowg\JsonApi\Schema\Link\Link;

// TODO(phase-2): this interface is slated for deletion when link-provider traits fold into Page value objects.

/**
 * Implemented by pagination link-provider traits to expose the standard set of
 * JSON:API pagination links (first / prev / self / next / last).
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 * @see https://jsonapi.org/format/1.1/#fetching-pagination
 */
interface PaginationLinkProviderInterface
{
    public function getSelfLink(string $uri, string $queryString): ?Link;

    public function getFirstLink(string $uri, string $queryString): ?Link;

    public function getLastLink(string $uri, string $queryString): ?Link;

    public function getPrevLink(string $uri, string $queryString): ?Link;

    public function getNextLink(string $uri, string $queryString): ?Link;
}
