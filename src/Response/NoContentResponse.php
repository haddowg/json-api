<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Server\ServerInterface;

/**
 * An empty `204 No Content` response: the body and the `Content-Type` header are
 * omitted entirely (a `204` carries neither). The common case is a successful
 * resource deletion, or any mutation the server completes with nothing to
 * return.
 *
 * The document-level members ({@see withMeta()}, {@see withLinks()}, …) do not
 * apply — there is no body to carry them — but {@see withHeader()} still applies,
 * so response headers can be set.
 */
final class NoContentResponse extends AbstractResponse
{
    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        return new RenderedDocument([], 204, hasBody: false);
    }
}
