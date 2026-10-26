<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A {@see SerializerResolverInterface} that also carries whether the server rejects a
 * read-only attribute sent in a write body. {@see AbstractResource} reads it off its
 * injected resolver when hydrating; a resolver that does not implement it means the
 * attribute is ignored.
 *
 * The {@see \haddowg\JsonApi\Server\Server} and its registry implement it. A resolver
 * that decorates the registry should implement it too and delegate, or the resources it
 * hydrates fall back to ignoring such attributes.
 */
interface RejectsReadOnlyAttributesInterface
{
    public function rejectsReadOnlyAttributes(): bool;
}
