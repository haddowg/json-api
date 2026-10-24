<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Resolves the `describedby` of a rendered link: a URI-reference into the server's
 * served OpenAPI document that points at the operation describing the link's target.
 *
 * A server is given one with {@see \haddowg\JsonApi\Server\Server::withDescribedByResolver()}.
 * The adapter that serves the document supplies it, because only the adapter knows the
 * document's URL. {@see SpecDescribedByResolver} is the implementation core ships.
 */
interface DescribedByResolverInterface
{
    /**
     * The `describedby` URI-reference for `$operation`, e.g.
     * `/api/v5/docs.json#/paths/~1albums~1%7Bid%7D/get`, or `null` when the document
     * doesn't describe that operation (so the link carries no `describedby`).
     */
    public function describedBy(OperationReference $operation): ?string;
}
