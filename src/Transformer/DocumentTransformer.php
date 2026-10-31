<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Schema\Data\SingleResourceData;
use haddowg\JsonApi\Schema\Document\DocumentInterface;
use haddowg\JsonApi\Schema\Document\ErrorDocumentInterface;

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

        $transformation->result = $this->renderErrorDocument($transformation->document, $transformation->additionalMeta, $transformation->result);

        return $transformation;
    }

    /**
     * An error document reads nothing off the request, so it renders without one.
     *
     * @param array<string, mixed> $additionalMeta
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function renderErrorDocument(ErrorDocumentInterface $document, array $additionalMeta = [], array $result = []): array
    {
        $result = $this->renderMetaMembers($document, $additionalMeta, $result);

        $errors = [];
        foreach ($document->getErrors() as $error) {
            $content = $error->transform();
            // An error with no members is still an error object, not a JSON array.
            $errors[] = $content === [] ? new \stdClass() : $content;
        }

        if ($errors !== []) {
            $result['errors'] = $errors;
        }

        return $result;
    }

    private function transformMetaMembers(ResourceDocumentTransformation $transformation): void
    {
        $transformation->result = $this->renderMetaMembers($transformation->document, $transformation->additionalMeta, $transformation->result);
    }

    /**
     * @param array<string, mixed> $additionalMeta
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function renderMetaMembers(DocumentInterface $document, array $additionalMeta, array $result): array
    {
        $jsonApi = $document->getJsonApi()?->transform() ?? [];
        if ($jsonApi !== []) {
            $result['jsonapi'] = $jsonApi;
        }

        $meta = $document->getMeta();
        foreach ($additionalMeta as $metaKey => $metaValue) {
            $meta[$metaKey] = $metaValue;
        }

        // Merge document-level meta *under* anything already present (the relationship
        // document path sets the relationship's own meta first), so document meta is
        // additive and the more specific members win on key conflict.
        $existingMeta = $result['meta'] ?? [];
        if (\is_array($existingMeta)) {
            $meta = \array_merge($meta, $existingMeta);
        }

        if ($meta !== []) {
            $result['meta'] = $meta;
        }

        $links = $document->getLinks();
        if ($links !== null) {
            $existingLinks = $result['links'] ?? [];
            $mergedLinks = \is_array($existingLinks)
                ? \array_merge($links->transform(), $existingLinks)
                : $links->transform();

            if ($mergedLinks !== []) {
                $result['links'] = $mergedLinks;
            }
        }

        return $result;
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

        // A relationship endpoint's document is **linkage-only**: it carries the linkage
        // `data` (and top-level jsonapi/meta/links), never a compound `included`. `?include`
        // is not part of the relationship-endpoint contract — it is tolerated but does not
        // produce an `included` member — so no included set (requested or default) is emitted
        // here (unlike a primary/related resource document).
    }
}
