<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A {@see SerializerResolverInterface} that also carries the server's
 * {@see StorageCastInterface}. {@see AbstractResource} reads it off its injected
 * resolver; a resolver that does not implement it means no cast.
 *
 * The {@see \haddowg\JsonApi\Server\Server} and its registry implement it. A resolver
 * that decorates the registry should implement it too and delegate.
 */
interface ProvidesStorageCastInterface
{
    public function storageCast(): ?StorageCastInterface;
}
