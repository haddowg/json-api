<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Server metadata that says how the server renders the links it builds, so the
 * document describes the links the responses carry.
 *
 * A separate, opt-in contract rather than methods on {@see ServerMetadataInterface},
 * the same shape as {@see ContributesErrorCodes}. Metadata that doesn't implement it
 * projects link objects without `describedby`, which is what a server renders by
 * default.
 */
interface DeclaresLinkObjects extends ServerMetadataInterface
{
    /**
     * Whether the server renders its built links as link objects (`{"href": …}`)
     * rather than bare URI strings. Mirrors
     * {@see \haddowg\JsonApi\Server\Server::withLinkObjects()}.
     */
    public function rendersLinkObjects(): bool;

    /**
     * The URI-reference the served document is reachable at (e.g.
     * `/api/v5/docs.json`), which each link's `describedby` points into, or `null`
     * when links carry no `describedby`. Mirrors the
     * {@see \haddowg\JsonApi\OpenApi\DescribedByResolverInterface} the server renders with.
     */
    public function documentUrl(): ?string;
}
