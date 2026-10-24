<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Server;

use haddowg\JsonApi\OpenApi\DescribedByResolverInterface;

/**
 * How a server renders the links it builds itself: the by-convention resource and
 * relationship links, the top-level `self` and pagination links, and contributed links.
 *
 * A separate, opt-in contract next to {@see ServerInterface}, so an existing
 * implementation keeps working. One that doesn't implement it renders link objects
 * without `describedby`, the same as a {@see Server} with no resolver.
 */
interface ConfiguresLinksInterface
{
    /**
     * Whether built links render as JSON:API link objects (`{"href": …}`), `true` by
     * default, or as bare URI strings.
     */
    public function rendersLinkObjects(): bool;

    /**
     * The resolver that adds a `describedby` to each link object, or `null` for none.
     */
    public function describedByResolver(): ?DescribedByResolverInterface;
}
