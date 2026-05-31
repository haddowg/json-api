<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Data\SingleResourceData;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * Concrete meta-only document: a top-level response carrying jsonapi/meta/links
 * but no primary `data`. Driven through {@see \haddowg\JsonApi\Transformer\DocumentTransformer::transformMetaDocument()},
 * which never touches the data members, so {@see getData()}/{@see getRelationshipData()}
 * are inert no-ops required only to satisfy the interface.
 *
 * @internal
 */
final class MetaDocument extends AbstractResourceDocument
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        private readonly ?JsonApiObject $jsonApi,
        private readonly array $meta,
        private readonly ?DocumentLinks $links,
    ) {}

    public function getJsonApi(): ?JsonApiObject
    {
        return $this->jsonApi;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getLinks(): ?DocumentLinks
    {
        return $this->links;
    }

    /**
     * @internal Never reached on the meta path; returns empty data.
     */
    public function getData(ResourceDocumentTransformation $transformation, ResourceTransformer $transformer): DataInterface
    {
        return new SingleResourceData();
    }

    /**
     * @internal Never reached on the meta path.
     *
     * @return array<string, mixed>|null
     */
    public function getRelationshipData(
        ResourceDocumentTransformation $transformation,
        ResourceTransformer $transformer,
        DataInterface $data,
    ): ?array {
        return null;
    }
}
