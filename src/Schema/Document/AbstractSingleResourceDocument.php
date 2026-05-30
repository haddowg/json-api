<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Data\SingleResourceData;
use haddowg\JsonApi\Schema\Resource\ResourceInterface;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * Base for documents whose primary data is a single resource.
 *
 * @internal
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
abstract class AbstractSingleResourceDocument extends AbstractResourceDocument
{
    public function __construct(protected ResourceInterface $resource) {}

    public function getResource(): ResourceInterface
    {
        if ($this->request !== null) {
            $this->resource->initializeTransformation($this->request, $this->object);
        }

        return $this->resource;
    }

    /**
     * Returns the resource ID for the current domain object.
     *
     * Shortcut for the resource serializer's getId().
     */
    public function getResourceId(): string
    {
        return $this->getResource()->getId($this->object);
    }

    /**
     * @internal
     */
    public function getData(ResourceDocumentTransformation $transformation, ResourceTransformer $transformer): DataInterface
    {
        $resourceTransformation = new ResourceTransformation(
            $this->getResource(),
            $transformation->object,
            '',
            $transformation->request,
            $transformation->basePath,
            $transformation->requestedRelationshipName,
            '',
        );
        $data = new SingleResourceData();

        $resourceObject = $transformer->transformToResourceObject($resourceTransformation, $data);
        if ($resourceObject !== null) {
            $data->addPrimaryResource($resourceObject);
        }

        return $data;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>|null
     */
    public function getRelationshipData(
        ResourceDocumentTransformation $transformation,
        ResourceTransformer $transformer,
        DataInterface $data,
    ): ?array {
        $resourceTransformation = new ResourceTransformation(
            $this->getResource(),
            $transformation->object,
            '',
            $transformation->request,
            $transformation->basePath,
            $transformation->requestedRelationshipName,
            $transformation->requestedRelationshipName,
        );

        return $transformer->transformToRelationshipObject($resourceTransformation, $data);
    }
}
