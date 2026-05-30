<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Document\AbstractResourceDocument;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * Configurable {@see AbstractResourceDocument} test double.
 */
final class StubResourceDocument extends AbstractResourceDocument
{
    /**
     * @param array<string, mixed>      $meta
     * @param array<string, mixed>|null $relationshipResponseContent
     */
    public function __construct(
        private readonly ?JsonApiObject $jsonApi = null,
        private readonly array $meta = [],
        private readonly ?DocumentLinks $links = null,
        private readonly ?DataInterface $data = null,
        private readonly ?array $relationshipResponseContent = [],
    ) {}

    public function getJsonApi(): ?JsonApiObject
    {
        return $this->jsonApi;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getLinks(): ?DocumentLinks
    {
        return $this->links;
    }

    public function getData(ResourceDocumentTransformation $transformation, ResourceTransformer $transformer): DataInterface
    {
        return $this->data ?? new DummyData();
    }

    public function getRelationshipData(
        ResourceDocumentTransformation $transformation,
        ResourceTransformer $transformer,
        DataInterface $data,
    ): ?array {
        $ownData = $this->getData($transformation, $transformer);

        $data->setIncludedResources($ownData->transformIncluded());

        return $this->relationshipResponseContent;
    }

    public function getRequest(): ?JsonApiRequestInterface
    {
        return $this->request;
    }

    public function getObject(): mixed
    {
        return $this->object;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdditionalMeta(): array
    {
        return $this->additionalMeta;
    }
}
