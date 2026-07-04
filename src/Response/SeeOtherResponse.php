<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Server\ServerInterface;

/**
 * An empty `303 See Other` response: the body and the `Content-Type` header are
 * omitted (a `303` carries neither), and the `Location` header points elsewhere.
 *
 * The completion half of the async lifecycle (JSON:API 1.1 §"Asynchronous
 * Processing"): once the job an {@see AcceptedResponse} advertised has finished,
 * a `GET` on that job resource redirects here — `Location` is the URL of the
 * resource the operation produced. Also serves any handler that needs to redirect
 * a client to a canonical resource URL.
 *
 * Like {@see NoContentResponse}, the document-level members do not apply (there is
 * no body), but {@see withHeader()} still applies.
 */
final class SeeOtherResponse extends AbstractResponse
{
    private function __construct() {}

    /**
     * A `303` whose `Location` header is the given URL — the resource the completed
     * operation produced (or any canonical URL to redirect the client to).
     */
    public static function to(string $location): self
    {
        $self = new self();
        $self->headers['Location'] = $location;

        return $self;
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        return new RenderedDocument([], 303, hasBody: false);
    }
}
