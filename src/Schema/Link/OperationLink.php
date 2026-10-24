<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link;

use haddowg\JsonApi\OpenApi\OperationReference;

/**
 * A link that names the OpenAPI operation describing its target, so the server can
 * render it as a link object with a `describedby` member.
 *
 * Return one from a {@see \haddowg\JsonApi\Serializer\ResourceLinkContributorInterface}
 * (e.g. a custom action's link, naming the action's operation). Where the server
 * renders contributed links as link objects and has a
 * {@see \haddowg\JsonApi\OpenApi\DescribedByResolverInterface}, the rendered object
 * carries `describedby`; otherwise it renders like a plain {@see Link}.
 */
final readonly class OperationLink extends Link
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        string $href,
        public OperationReference $operation,
        array $meta = [],
    ) {
        parent::__construct($href, $meta);
    }
}
