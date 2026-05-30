<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Schema\Data\SingleResourceData;

/**
 * Orchestrates transformation of a whole JSON:API document into its array
 * representation: top-level meta/links/jsonapi members plus primary data,
 * included resources (compound document) or errors. Delegates per-resource
 * work to a {@see ResourceTransformer}.
 *
 * @internal
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
final class DocumentTransformer
{
    private ResourceTransformer $resourceTransformer;

    public function __construct()
    {
        $this->resourceTransformer = new ResourceTransformer();
    }

    public function transformResourceDocument(ResourceDocumentTransformation $transformation): ResourceDocumentTransformation
    {
        $transformation = clone $transformation;

        $transformation->document->initializeTransformation($transformation);
        $this->transformMetaMembers($transformation);
        $this->transformResourceDataMembers($transformation);
        $transformation->document->clearTransformation();

        return $transformation;
    }

    public function transformMetaDocument(ResourceDocumentTransformation $transformation): ResourceDocumentTransformation
    {
        $transformation = clone $transformation;

        $transformation->document->initializeTransformation($transformation);
        $this->transformMetaMembers($transformation);
        $transformation->document->clearTransformation();

        return $transformation;
    }

    public function transformRelationshipDocument(ResourceDocumentTransformation $transformation): ResourceDocumentTransformation
    {
        $transformation = clone $transformation;

        $transformation->document->initializeTransformation($transformation);
        $this->transformRelationshipDataMembers($transformation);
        $transformation->document->clearTransformation();

        return $transformation;
    }

    public function transformErrorDocument(ErrorDocumentTransformation $transformation): ErrorDocumentTransformation
    {
        $transformation = clone $transformation;

        $this->transformMetaMembers($transformation);
        $this->transformErrors($transformation);

        return $transformation;
    }

    private function transformMetaMembers(ResourceDocumentTransformation|ErrorDocumentTransformation $transformation): void
    {
        $jsonApi = $transformation->document->getJsonApi();
        if ($jsonApi !== null) {
            $transformation->result['jsonapi'] = $jsonApi->transform();
        }

        $meta = $transformation->document->getMeta();
        foreach ($transformation->additionalMeta as $metaKey => $metaValue) {
            $meta[$metaKey] = $metaValue;
        }

        if ($meta !== []) {
            $transformation->result['meta'] = $meta;
        }

        $links = $transformation->document->getLinks();
        if ($links !== null) {
            $transformation->result['links'] = $links->transform();
        }
    }

    private function transformResourceDataMembers(ResourceDocumentTransformation $transformation): void
    {
        $data = $transformation->document->getData($transformation, $this->resourceTransformer);

        $transformation->result['data'] = $data->transformPrimaryData();

        if ($data->hasIncludedResources() || $transformation->request->hasIncludedRelationships()) {
            $transformation->result['included'] = $data->transformIncluded();
        }
    }

    private function transformRelationshipDataMembers(ResourceDocumentTransformation $transformation): void
    {
        $data = new SingleResourceData();

        $result = $transformation->document->getRelationshipData($transformation, $this->resourceTransformer, $data);
        if ($result !== null) {
            $transformation->result = $result;
        }

        if ($data->hasIncludedResources() || $transformation->request->hasIncludedRelationships()) {
            $transformation->result['included'] = $data->transformIncluded();
        }
    }

    private function transformErrors(ErrorDocumentTransformation $transformation): void
    {
        $errors = [];
        foreach ($transformation->document->getErrors() as $error) {
            $errors[] = $error->transform();
        }

        if ($errors !== []) {
            $transformation->result['errors'] = $errors;
        }
    }
}
