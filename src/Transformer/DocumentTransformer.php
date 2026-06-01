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

        $this->transformMetaMembers($transformation);
        $this->transformResourceDataMembers($transformation);

        return $transformation;
    }

    public function transformMetaDocument(ResourceDocumentTransformation $transformation): ResourceDocumentTransformation
    {
        $transformation = clone $transformation;

        $this->transformMetaMembers($transformation);

        return $transformation;
    }

    public function transformRelationshipDocument(ResourceDocumentTransformation $transformation): ResourceDocumentTransformation
    {
        $transformation = clone $transformation;

        $this->transformRelationshipDataMembers($transformation);
        // A relationship document MAY also carry top-level jsonapi/meta/links; merge
        // them on top of the relationship's own data/links/meta.
        $this->transformMetaMembers($transformation);

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

        // Merge document-level meta *under* anything already present (the relationship
        // document path sets the relationship's own meta first), so document meta is
        // additive and the more specific members win on key conflict.
        $existingMeta = $transformation->result['meta'] ?? [];
        if (\is_array($existingMeta)) {
            $meta = \array_merge($meta, $existingMeta);
        }

        if ($meta !== []) {
            $transformation->result['meta'] = $meta;
        }

        $links = $transformation->document->getLinks();
        if ($links !== null) {
            $existingLinks = $transformation->result['links'] ?? [];
            $transformation->result['links'] = \is_array($existingLinks)
                ? \array_merge($links->transform(), $existingLinks)
                : $links->transform();
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
