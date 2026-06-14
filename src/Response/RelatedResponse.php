<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\CollectionDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * Response for a related-resources endpoint (`GET /articles/1/author`,
 * `GET /articles/1/comments`): the primary `data` is the related resource or
 * collection, serialized through the related resource's {@see SerializerInterface}.
 *
 * The parent domain object and the relationship name are stored for context
 * but do not affect the rendered body.
 *
 * Single vs collection is fixed at construction by the named constructor used
 * ({@see fromResource()} / {@see fromCollection()}). The domain data and its
 * resource serializer are not withable; the document-level members
 * ({@see withMeta()} etc.) are.
 */
final class RelatedResponse extends AbstractResponse
{
    use AppliesPaginationTrait;

    /**
     * @param \haddowg\JsonApi\Pagination\PageInterface<mixed>|null $page
     */
    private function __construct(
        private readonly mixed $related,
        private readonly SerializerInterface $relatedResource,
        private readonly bool $isCollection,
        private readonly ?\haddowg\JsonApi\Pagination\PageInterface $page = null,
    ) {}

    /**
     * A single related-resource response whose `data` is the related object.
     */
    public static function fromResource(
        mixed $related,
        SerializerInterface $relatedResource,
    ): self {
        return new self($related, $relatedResource, false);
    }

    /**
     * A related-collection response whose `data` is a list of related objects.
     *
     * @param iterable<mixed> $related
     */
    public static function fromCollection(
        iterable $related,
        SerializerInterface $relatedResource,
    ): self {
        return new self($related, $relatedResource, true);
    }

    /**
     * A paginated related-collection response: the `data` is the page's items,
     * and the document gains the pagination `links.{first,prev,self,next,last}`
     * and `meta.page` the {@see \haddowg\JsonApi\Pagination\Page} emits — scoped
     * to the related-collection URL the client hit (e.g. `/articles/1/comments`),
     * exactly as {@see DataResponse::fromPage()} paginates the primary
     * collection. A page that activates a profile (e.g. cursor pagination) causes
     * the response to advertise it.
     *
     * @template T
     *
     * @param \haddowg\JsonApi\Pagination\PageInterface<T> $page
     */
    public static function fromPage(
        \haddowg\JsonApi\Pagination\PageInterface $page,
        SerializerInterface $relatedSerializer,
    ): self {
        return new self($page, $relatedSerializer, true, $page);
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        $document = $this->isCollection
            ? new CollectionDocument($this->relatedResource, $this->resolveJsonApi($server), $this->meta, $this->links)
            : new SingleResourceDocument($this->relatedResource, $this->resolveJsonApi($server), $this->meta, $this->links);

        $transformation = new ResourceDocumentTransformation(
            $document,
            $this->related,
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

        $result = $this->applyTopLevelSelf($result, $server, $request);

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
