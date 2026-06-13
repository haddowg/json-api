<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Pagination\Page;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\CollectionDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * The common-case response: a document whose primary `data` is a resource or a
 * collection of resources, rendered through a {@see SerializerInterface}.
 *
 * Single vs collection is fixed at construction by the named constructor used
 * ({@see fromResource()} / {@see fromCollection()}) rather than inferred from the
 * runtime shape of the data — an iterable single resource is therefore never
 * mistaken for a collection. The domain data and its resource serializer are not
 * withable; the document-level members ({@see withMeta()} etc.) are.
 */
final class DataResponse extends AbstractResponse
{
    use AppliesPaginationTrait;

    /**
     * @param \haddowg\JsonApi\Pagination\PageInterface<mixed>|null $page
     */
    private function __construct(
        private readonly mixed $data,
        private readonly SerializerInterface $resource,
        private readonly bool $isCollection,
        private readonly ?\haddowg\JsonApi\Pagination\PageInterface $page = null,
    ) {}

    /**
     * A single-resource response whose `data` is the resource object.
     */
    public static function fromResource(mixed $object, SerializerInterface $resource): self
    {
        return new self($object, $resource, false);
    }

    /**
     * A collection response whose `data` is a list of resource objects.
     *
     * @param iterable<mixed> $objects
     */
    public static function fromCollection(iterable $objects, SerializerInterface $resource): self
    {
        return new self($objects, $resource, true);
    }

    /**
     * A paginated collection response: the `data` is the page's items, and the
     * document gains the pagination `links.{first,prev,next,last}` and
     * `meta.page` the {@see Page} emits. A page that activates a profile (e.g.
     * cursor pagination) causes the response to advertise it.
     *
     * @template T
     *
     * @param \haddowg\JsonApi\Pagination\PageInterface<T> $page
     */
    public static function fromPage(\haddowg\JsonApi\Pagination\PageInterface $page, SerializerInterface $resource): self
    {
        return new self($page, $resource, true, $page);
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
            $server->baseUri(),
            $server->maxIncludeDepth(),
        );

        $result = (new DocumentTransformer())->transformResourceDocument($transformation)->result;

        if ($this->page !== null) {
            $result = $this->applyPagination($result, $server, $request, $this->page);
        }

        return new RenderedDocument($result, 200);
    }

    /**
     * Adds the page's profile (if any) to the request-requested applied set, via
     * the shared {@see AppliesPaginationTrait::appliedPageProfiles()} helper.
     */
    protected function appliedProfiles(ServerInterface $server, JsonApiRequestInterface $request): array
    {
        return $this->appliedPageProfiles(parent::appliedProfiles($server, $request), $server, $this->page);
    }
}
