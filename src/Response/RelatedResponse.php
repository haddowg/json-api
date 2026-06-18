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
     * @param bool|null                                            $selfCountable the owning relation's
     *                                                                            `countable()`, gating
     *                                                                            `?withCount=_self_` on this
     *                                                                            related collection (the
     *                                                                            relation, not the related
     *                                                                            resource); `null` defers to
     *                                                                            the related serializer
     */
    private function __construct(
        private readonly mixed $related,
        private readonly SerializerInterface $relatedResource,
        private readonly bool $isCollection,
        private readonly ?\haddowg\JsonApi\Pagination\PageInterface $page = null,
        private readonly ?bool $selfCountable = null,
    ) {}

    /**
     * A single related-resource response whose `data` is the related object.
     *
     * A to-one related endpoint has no collection, so `?withCount=_self_` is always
     * invalid here — the response carries `selfCountable: false` so core's document
     * gate rejects `_self_` (400) regardless of the related resource's own
     * countability.
     */
    public static function fromResource(
        mixed $related,
        SerializerInterface $relatedResource,
    ): self {
        return new self($related, $relatedResource, false, null, false);
    }

    /**
     * A related-collection response whose `data` is a list of related objects.
     *
     * `$selfCountable` is the owning relation's `countable()` — when supplied it gates
     * `?withCount=_self_` on this collection against the relation (whose endpoint this
     * is) rather than the related resource.
     *
     * @param iterable<mixed> $related
     */
    public static function fromCollection(
        iterable $related,
        SerializerInterface $relatedResource,
        ?bool $selfCountable = null,
    ): self {
        return new self($related, $relatedResource, true, null, $selfCountable);
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
     * `$selfCountable` is the owning relation's `countable()`, gating
     * `?withCount=_self_` on this collection against the relation.
     *
     * @template T
     *
     * @param \haddowg\JsonApi\Pagination\PageInterface<T> $page
     */
    public static function fromPage(
        \haddowg\JsonApi\Pagination\PageInterface $page,
        SerializerInterface $relatedSerializer,
        ?bool $selfCountable = null,
    ): self {
        return new self($page, $relatedSerializer, true, $page, $selfCountable);
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
            $this->selfCountable,
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
