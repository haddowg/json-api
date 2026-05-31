<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\CollectionDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Schema\Resource\ResourceInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * The common-case response: a document whose primary `data` is a resource or a
 * collection of resources, rendered through a {@see ResourceInterface}.
 *
 * Single vs collection is fixed at construction by the named constructor used
 * ({@see fromResource()} / {@see fromCollection()}) rather than inferred from the
 * runtime shape of the data — an iterable single resource is therefore never
 * mistaken for a collection. The domain data and its resource serializer are not
 * withable; the document-level members ({@see withMeta()} etc.) are.
 */
final class DataResponse extends AbstractResponse
{
    private function __construct(
        private readonly mixed $data,
        private readonly ResourceInterface $resource,
        private readonly bool $isCollection,
    ) {}

    /**
     * A single-resource response whose `data` is the resource object.
     */
    public static function fromResource(mixed $object, ResourceInterface $resource): self
    {
        return new self($object, $resource, false);
    }

    /**
     * A collection response whose `data` is a list of resource objects.
     *
     * @param iterable<mixed> $objects
     */
    public static function fromCollection(iterable $objects, ResourceInterface $resource): self
    {
        return new self($objects, $resource, true);
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        $document = $this->isCollection
            ? new CollectionDocument($this->resource, $this->resolveJsonApi($server), $this->meta, $this->links)
            : new SingleResourceDocument($this->resource, $this->resolveJsonApi($server), $this->meta, $this->links);

        $transformation = new ResourceDocumentTransformation(
            $document,
            $this->data,
            $request,
            '',
            '',
            [],
        );

        $result = (new DocumentTransformer())->transformResourceDocument($transformation)->result;

        return new RenderedDocument($result, 200);
    }
}
