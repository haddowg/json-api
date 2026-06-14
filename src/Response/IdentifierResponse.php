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
            $server->baseUri(),
            $server->maxIncludeDepth(),
        );

        $result = (new DocumentTransformer())->transformRelationshipDocument($transformation)->result;

        $result = $this->applyTopLevelSelf($result, $server, $request);

        return new RenderedDocument($result, 200);
    }
}
