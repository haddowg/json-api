<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\ErrorDocument;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ErrorDocumentTransformation;

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
        return new self($exception->getErrors(), $exception->getStatusCode());
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

        $document = new ErrorDocument($this->rebaseErrors($baseUri));
        $document->setJsonApi($this->resolveJsonApi($server));
        $document->setMeta($this->meta);
        $document->setLinks($this->links?->rebasedTo($baseUri));

        $transformation = new ErrorDocumentTransformation($document, $request, []);

        $result = (new DocumentTransformer())->transformErrorDocument($transformation)->result;

        return new RenderedDocument($result, $document->getStatusCode($this->statusOverride));
    }

    /**
     * Returns the errors with each one's `links` ({@see \haddowg\JsonApi\Schema\Link\ErrorLinks})
     * rebound to the resolved base URI, leaving an error without links untouched.
     *
     * @return list<Error>
     */
    private function rebaseErrors(string $baseUri): array
    {
        $rebased = [];
        foreach ($this->errors as $error) {
            $rebased[] = $error->links === null
                ? $error
                : new Error(
                    id: $error->id,
                    status: $error->status,
                    code: $error->code,
                    title: $error->title,
                    detail: $error->detail,
                    source: $error->source,
                    links: $error->links->rebasedTo($baseUri),
                    meta: $error->meta,
                );
        }

        return $rebased;
    }
}
