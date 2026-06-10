<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Schema\Data\CollectionData;
use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * Base for documents whose primary data is a collection of resources.
 *
 * @internal
 *
 */
abstract class AbstractCollectionDocument extends AbstractResourceDocument
{
    public function __construct(protected SerializerInterface $resource) {}

    public function getResource(): SerializerInterface
    {
        return $this->resource;
    }

    /**
     * @internal
     */
    public function getData(ResourceDocumentTransformation $transformation, ResourceTransformer $transformer): DataInterface
    {
        $resourceTransformation = new ResourceTransformation(
            $this->getResource(),
            null,
            '',
            $transformation->request,
            $transformation->basePath,
            $transformation->requestedRelationshipName,
            '',
            $transformation->baseUri,
        );
        $data = new CollectionData();

        $items = \is_iterable($transformation->object) ? $transformation->object : [];
        foreach ($items as $item) {
            $resourceTransformation->object = $item;

            $resourceObject = $transformer->transformToResourceObject($resourceTransformation, $data);
            if ($resourceObject !== null) {
                $data->addPrimaryResource($resourceObject);
            }
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
        return null;
    }
}
