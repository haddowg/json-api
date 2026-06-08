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
        $document = new ErrorDocument($this->errors);
        $document->setJsonApi($this->resolveJsonApi($server));
        $document->setMeta($this->meta);
        $document->setLinks($this->links);

        $transformation = new ErrorDocumentTransformation($document, $request, []);

        $result = (new DocumentTransformer())->transformErrorDocument($transformation)->result;

        return new RenderedDocument($result, $document->getStatusCode($this->statusOverride));
    }
}
