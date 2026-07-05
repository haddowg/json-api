<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * Response for a relationship endpoint (`GET /articles/1/relationships/comments`):
 * emits resource-identifier linkage only — `type` + `id` objects with no
 * `attributes` or `relationships` — driven by the named relationship on the
 * parent resource's {@see SerializerInterface}.
 *
 * The parent domain object is transformed through `$parentResource` with the
 * `$relationshipName` as the `requestedRelationshipName`, which routes the
 * transformer through {@see \haddowg\JsonApi\Schema\Document\AbstractSingleResourceDocument::getRelationshipData()}
 * → {@see \haddowg\JsonApi\Transformer\ResourceTransformer::transformToRelationshipObject()}.
 */
final class IdentifierResponse extends AbstractResponse
{
    use AppliesPaginationTrait;

    /**
     * The page that windowed the linkage, attached via {@see withPage()} so its
     * profile (if any) is advertised. It never alters the document body: linkage
     * documents stay links-only, with the pagination links rendered through the
     * relationship-pagination seam
     * ({@see \haddowg\JsonApi\Server\Server::withRelationshipPagination()}), and
     * `meta.page` is deliberately not emitted (ADR 0124).
     *
     * @var \haddowg\JsonApi\Pagination\PageInterface<mixed>|null
     */
    private ?\haddowg\JsonApi\Pagination\PageInterface $page = null;

    private function __construct(
        private readonly mixed $parent,
        private readonly SerializerInterface $parentResource,
        private readonly string $relationshipName,
    ) {}

    /**
     * A relationship-linkage response for the named relationship on the parent.
     */
    public static function forRelationship(
        mixed $parent,
        SerializerInterface $parentResource,
        string $relationshipName,
    ): self {
        return new self($parent, $parentResource, $relationshipName);
    }

    /**
     * The same response with the page that windowed the linkage attached, so a
     * page that activates a profile (e.g. cursor pagination) causes the response
     * to advertise it — in `jsonapi.profile` and the `Content-Type` `profile`
     * media-type parameter, exactly as {@see RelatedResponse::fromPage()} does.
     *
     * The rendered body is otherwise byte-identical to the page-less response:
     * the pagination links flow through the relationship-pagination seam, and no
     * `meta.page` is added (linkage documents are links-only — ADR 0124).
     *
     * @template T
     *
     * @param \haddowg\JsonApi\Pagination\PageInterface<T> $page
     */
    public function withPage(\haddowg\JsonApi\Pagination\PageInterface $page): self
    {
        $self = clone $this;
        $self->page = $page;

        return $self;
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        $document = new SingleResourceDocument(
            $this->parentResource,
            $this->resolveJsonApi($server),
            $this->meta,
            $this->links,
        );

        $transformation = new ResourceDocumentTransformation(
            $document,
            $this->parent,
            $request,
            '',
            $this->relationshipName,
            [],
            \haddowg\JsonApi\Server\RequestBaseUri::resolve($server->baseUri(), $request->getUri()),
            $server->maxIncludeDepth(),
        );

        $result = (new DocumentTransformer())->transformRelationshipDocument($transformation)->result;

        $result = $this->applyTopLevelSelf($result, $server, $request);

        return new RenderedDocument($result, 200);
    }

    /**
     * Adds the attached page's profile (if any) to the request-requested applied
     * set, via the shared {@see AppliesPaginationTrait::appliedPageProfiles()}
     * helper — the same wiring as {@see RelatedResponse}. With no page attached
     * this is the parent behaviour unchanged.
     */
    protected function appliedProfiles(ServerInterface $server, JsonApiRequestInterface $request): array
    {
        return $this->appliedPageProfiles(parent::appliedProfiles($server, $request), $server, $this->page);
    }
}
