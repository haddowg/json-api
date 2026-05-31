<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;

/**
 * Transforms a single domain object into a JSON:API resource object, resource
 * identifier, or relationship object, recursing through relationships to
 * populate the compound-document `included` accumulator and enforcing sparse
 * fieldsets and inclusion rules.
 *
 * @internal
 *
 */
final class ResourceTransformer
{
    /**
     * Transforms the original resource to a JSON:API resource object.
     *
     * @return array<string, mixed>|null
     */
    public function transformToResourceObject(ResourceTransformation $transformation, DataInterface $data): ?array
    {
        if ($transformation->object === null || $transformation->resource === null) {
            return null;
        }

        $this->transformResourceIdentifier($transformation);
        $this->transformLinksObject($transformation);
        $this->transformAttributesObject($transformation);
        $this->transformRelationshipsObject($transformation, $data);

        return $transformation->result;
    }

    /**
     * Transforms the original resource to a JSON:API resource identifier.
     *
     * @return array<string, mixed>|null
     */
    public function transformToResourceIdentifier(ResourceTransformation $transformation): ?array
    {
        if ($transformation->object === null || $transformation->resource === null) {
            return null;
        }

        $this->transformResourceIdentifier($transformation);

        return $transformation->result;
    }

    /**
     * Transforms a relationship of the original resource to a JSON:API relationship.
     *
     * @return array<string, mixed>|null
     */
    public function transformToRelationshipObject(ResourceTransformation $transformation, DataInterface $data): ?array
    {
        if ($transformation->object === null || $transformation->resource === null) {
            return null;
        }

        $relationships = $transformation->resource->getRelationships($transformation->object, $transformation->request);
        if (isset($relationships[$transformation->requestedRelationshipName]) === false) {
            throw new RelationshipNotExists($transformation->requestedRelationshipName);
        }

        $defaultRelationships = \array_flip($transformation->resource->getDefaultIncludedRelationships($transformation->object));

        $transformation->result = $this->transformRelationshipObject(
            $transformation,
            $data,
            $relationships[$transformation->currentRelationshipName],
            $defaultRelationships,
        );

        return $transformation->result;
    }

    private function transformResourceIdentifier(ResourceTransformation $transformation): void
    {
        if ($transformation->object === null || $transformation->resource === null) {
            return;
        }

        $type = $transformation->resource->getType($transformation->object);
        $transformation->resourceType = $type;
        $id = $transformation->resource->getId($transformation->object);

        $transformation->result = [
            'type' => $type,
            'id' => $id,
        ];

        $meta = $transformation->resource->getMeta($transformation->object, $transformation->request);
        if ($meta !== []) {
            $transformation->result['meta'] = $meta;
        }
    }

    private function transformLinksObject(ResourceTransformation $transformation): void
    {
        if ($transformation->object === null || $transformation->resource === null) {
            return;
        }

        $links = $transformation->resource->getLinks($transformation->object, $transformation->request);

        if ($links !== null) {
            $transformation->result['links'] = $links->transform();
        }
    }

    private function transformAttributesObject(ResourceTransformation $transformation): void
    {
        if ($transformation->object === null || $transformation->resource === null) {
            return;
        }

        $attributes = $transformation->resource->getAttributes($transformation->object, $transformation->request);

        $transformedAttributes = [];
        foreach ($attributes as $name => $attribute) {
            if ($transformation->request->isIncludedField($transformation->resourceType, $name)) {
                $transformedAttributes[$name] = $attribute($transformation->object, $transformation->request, $name);
            }
        }

        if ($transformedAttributes !== []) {
            $transformation->result['attributes'] = $transformedAttributes;
        }
    }

    private function transformRelationshipsObject(ResourceTransformation $transformation, DataInterface $data): void
    {
        if ($transformation->object === null || $transformation->resource === null) {
            return;
        }

        $relationships = $transformation->resource->getRelationships($transformation->object, $transformation->request);
        $defaultRelationships = \array_flip($transformation->resource->getDefaultIncludedRelationships($transformation->object));

        $this->validateRelationships($transformation, $relationships);

        $transformedRelationships = [];
        foreach ($relationships as $relationshipName => $relationshipCallback) {
            $transformation->currentRelationshipName = $relationshipName;
            $relationshipObject = $this->transformRelationshipObject(
                $transformation,
                $data,
                $relationshipCallback,
                $defaultRelationships,
            );

            if ($relationshipObject !== null && $relationshipObject !== []) {
                $transformedRelationships[$relationshipName] = $relationshipObject;
            }
        }

        if ($transformedRelationships !== []) {
            $transformation->result['relationships'] = $transformedRelationships;
        }

        $transformation->currentRelationshipName = '';
    }

    /**
     * @param callable(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface, string): AbstractRelationship $relationshipCallback
     * @param array<string, int>                                                                              $defaultRelationships
     *
     * @return array<string, mixed>|null
     */
    private function transformRelationshipObject(
        ResourceTransformation $transformation,
        DataInterface $data,
        callable $relationshipCallback,
        array $defaultRelationships,
    ): ?array {
        $relationshipName = $transformation->currentRelationshipName;

        if (
            $transformation->request->isIncludedField($transformation->resourceType, $relationshipName) === false &&
            $transformation->request->isIncludedRelationship($transformation->basePath, $relationshipName, $defaultRelationships) === false
        ) {
            return null;
        }

        $relationship = $relationshipCallback($transformation->object, $transformation->request, $relationshipName);

        return $relationship->transform($transformation, $this, $data, $defaultRelationships);
    }

    /**
     * @param array<string, mixed> $relationships
     */
    private function validateRelationships(ResourceTransformation $transformation, array $relationships): void
    {
        $requestedRelationships = $transformation->request->getIncludedRelationships($transformation->basePath);

        $nonExistentRelationships = \array_diff($requestedRelationships, \array_keys($relationships));
        if ($nonExistentRelationships !== []) {
            foreach ($nonExistentRelationships as $key => $relationship) {
                $nonExistentRelationships[$key] = ($transformation->basePath !== '' ? $transformation->basePath . '.' : '') . $relationship;
            }

            throw new InclusionUnrecognized(\array_values($nonExistentRelationships));
        }
    }
}
