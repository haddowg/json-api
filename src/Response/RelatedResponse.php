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
 * (e.g. future self-link generation) but do not affect the Phase-1 body.
 *
 * Single vs collection is fixed at construction by the named constructor used
 * ({@see fromResource()} / {@see fromCollection()}). The domain data and its
 * resource serializer are not withable; the document-level members
 * ({@see withMeta()} etc.) are.
 */
final class RelatedResponse extends AbstractResponse
{
    private function __construct(
        public readonly mixed $parent,
        public readonly string $relationshipName,
        private readonly mixed $related,
        private readonly SerializerInterface $relatedResource,
        private readonly bool $isCollection,
    ) {}

    /**
     * A single related-resource response whose `data` is the related object.
     */
    public static function fromResource(
        mixed $parent,
        string $relationshipName,
        mixed $related,
        SerializerInterface $relatedResource,
    ): self {
        return new self($parent, $relationshipName, $related, $relatedResource, false);
    }

    /**
     * A related-collection response whose `data` is a list of related objects.
     *
     * @param iterable<mixed> $related
     */
    public static function fromCollection(
        mixed $parent,
        string $relationshipName,
        iterable $related,
        SerializerInterface $relatedResource,
    ): self {
        return new self($parent, $relationshipName, $related, $relatedResource, true);
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
        );

        $result = (new DocumentTransformer())->transformResourceDocument($transformation)->result;

        return new RenderedDocument($result, 200);
    }
}
