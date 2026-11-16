<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A type that may be a singleton: it serves one resource per caller at `/{uriType}`, with no
 * `{id}` segment, and resolves which resource that is from the request.
 *
 * {@see AbstractResource} implements it, declared with `public static bool $singleton = true`
 * and an override of {@see resolveSingletonId()}. A type composed without
 * {@see AbstractResource}, such as a bare serializer, implements it directly and returns
 * `true` from {@see isSingleton()}.
 *
 * A singleton keeps a real id. Its resource objects carry it and other types link to it by
 * `{type, id}` as usual; only the links into its own endpoints drop the id. Because those
 * links are always `/{uriType}`, a singleton's data layer must expose only the caller's row:
 * a resource rendered at `/{uriType}` that isn't the caller's would link to someone else's
 * endpoint. That narrowing is the author's responsibility.
 */
interface SingletonResourceInterface
{
    /**
     * Whether the type is a singleton. Static, so a registry or a route loader can read it
     * without building the type.
     */
    public static function isSingleton(): bool;

    /**
     * The id of the caller's resource, or `null` when the caller has none, which answers
     * `404`. An adapter resolves it once per request and then runs its ordinary fetch and
     * update paths with that id.
     */
    public function resolveSingletonId(SingletonContext $context): ?string;
}
