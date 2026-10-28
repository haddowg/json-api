<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\ErrorDocument;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;

/**
 * An error response: a top-level document carrying one or more {@see Error}
 * objects. The HTTP status is derived from the errors by
 * {@see ErrorDocument::getStatusCode()} (errors sharing one status use it; a
 * mix is rounded to the nearest applicable class) — unless built from a typed
 * exception, which supplies the status it declares.
 */
final class ErrorResponse extends AbstractResponse
{
    /**
     * @param list<Error> $errors
     */
    private function __construct(private readonly array $errors, private readonly ?int $statusOverride = null) {}

    public static function fromErrors(Error ...$errors): self
    {
        return new self(\array_values($errors));
    }

    public static function fromException(\haddowg\JsonApi\Exception\JsonApiExceptionInterface $exception): self
    {
        $response = new self($exception->getErrors(), $exception->getStatusCode());
        if ($exception instanceof \haddowg\JsonApi\Exception\CarriesResponseHeadersInterface) {
            foreach ($exception->responseHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        // Error links (the document-level `links` and each error's `about`/`type`)
        // are author/exception supplied, not built downstream from a threaded base
        // — so resolve the base here and rebind it onto them, the same model the
        // resource/relationship/pagination/self links follow. The rebind is a no-op
        // for a link container that already pinned its own base (a `withBaseUri(...)`
        // choice) and for an absolute href (the prefix is skipped), so a
        // documentation URL is never corrupted.
        $baseUri = \haddowg\JsonApi\Server\RequestBaseUri::resolve($server->baseUri(), $request->getUri());

        $document = ErrorRenderer::buildDocument(
            $this->errors,
            $this->resolveJsonApi($server),
            $this->meta,
            $this->links,
            $server->errorMessageResolver(),
            $baseUri,
        );

        return new RenderedDocument(
            (new DocumentTransformer())->renderErrorDocument($document),
            $document->getStatusCode($this->statusOverride),
        );
    }

    protected function describesOperation(): bool
    {
        return false;
    }
}
