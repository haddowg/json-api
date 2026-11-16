<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A {@see SerializerResolverInterface} that also says which types are singletons and
 * resolves a singleton's id for the caller. The transformer reads it off a rendered
 * resource's injected resolver to link a singleton's endpoints at `/{uriType}`, and
 * {@see \haddowg\JsonApi\Operation\OperationFactory} reads it off the server to resolve a
 * singleton target.
 *
 * The {@see \haddowg\JsonApi\Server\Server} and its registry implement it. A resolver that
 * decorates the registry should implement it too and delegate, or the singletons it renders
 * link to `/{uriType}/{id}`, which no route serves.
 */
interface DeclaresSingletonTypesInterface
{
    /**
     * Whether the JSON:API type `$type` is a singleton.
     */
    public function isSingleton(string $type): bool;

    /**
     * The caller's id for the singleton type `$type`, or `null` when the caller has none or
     * the type isn't a singleton.
     */
    public function resolveSingletonId(string $type, SingletonContext $context): ?string;
}
