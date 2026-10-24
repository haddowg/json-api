<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A {@see SerializerResolverInterface} that also carries the server's
 * {@see RelationAuthorizerInterface}. Relations and {@see AbstractResource} read it
 * off their injected resolver; a resolver that does not implement it means no
 * authorizer.
 *
 * The {@see \haddowg\JsonApi\Server\Server} and its registry implement it. A resolver
 * that decorates the registry should implement it too and delegate, or the
 * relationships it builds skip the authorizer.
 */
interface ProvidesRelationAuthorizerInterface
{
    public function relationAuthorizer(): ?RelationAuthorizerInterface;
}
