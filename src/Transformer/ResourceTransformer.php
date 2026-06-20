<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Transformer;

use haddowg\JsonApi\Exception\InclusionNotAllowed;
use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SelfLinkAwareInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;

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

        $defaultRelationships = $this->defaultIncludedRelationships($transformation->resource, $transformation->request, $transformation->object);

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

        $transformed = $links !== null ? $links->transform() : [];

        // The spec RECOMMENDS a resource carry a by-convention `self` link
        // (`{baseUri}/{uriType}/{id}`). It is emitted for every serializer unless
        // it opts out via SelfLinkAwareInterface, the id is empty (a not-yet-
        // persisted resource has no self), or getLinks() already supplied a self
        // (a hand-written self wins). Built here, the only layer that knows the
        // resolved type + id and the configured base URI.
        if (isset($transformed['self']) === false) {
            $self = $this->conventionSelfLink($transformation);
            if ($self !== null) {
                $transformed['self'] = $self;
            }
        }

        // Emit `links` when getLinks() supplied a (possibly empty) container, as
        // before, or when the convention self was added.
        if ($links !== null || $transformed !== []) {
            $transformation->result['links'] = $transformed;
        }
    }

    /**
     * Builds the by-convention resource `self` URL (`{baseUri}/{uriType}/{id}`),
     * or `null` when the resource opted out or has no id. The path segment is the
     * serializer's URI type (so a resource whose JSON:API type differs from its
     * URL segment links correctly); a serializer that is not URI-type-aware falls
     * back to its JSON:API type, mirroring {@see AbstractRelationship::conventionLinks()}.
     */
    private function conventionSelfLink(ResourceTransformation $transformation): ?string
    {
        $resource = $transformation->resource;
        if ($resource === null) {
            return null;
        }

        if ($resource instanceof SelfLinkAwareInterface && $resource->emitsSelfLink() === false) {
            return null;
        }

        $id = $resource->getId($transformation->object);
        if ($id === '') {
            return null;
        }

        $uriType = $resource instanceof UriTypeAwareInterface
            ? $resource->uriType()
            : $resource->getType($transformation->object);
        if ($uriType === '') {
            $uriType = $transformation->resourceType;
        }
        if ($uriType === '') {
            return null;
        }

        return $transformation->baseUri . '/' . $uriType . '/' . $id;
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
        $defaultRelationships = $this->defaultIncludedRelationships($transformation->resource, $transformation->request, $transformation->object);

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
                $nonExistentRelationships[$key] = $this->prefixBasePath($transformation->basePath, $relationship);
            }

            throw new InclusionUnrecognized(\array_values($nonExistentRelationships));
        }

        // A requested relationship that exists but has opted out of inclusion
        // (Capability A) — evaluated per-resource-level so it covers a relation
        // reached at any path. Capability C (the root allow-list) is checked once,
        // up front, against the primary resource.
        $resource = $transformation->resource;
        if ($resource instanceof IncludeControlsInterface) {
            $nonIncludable = $resource->getNonIncludableRelationships($transformation->request, $transformation->object);
            $offending = \array_values(\array_intersect($requestedRelationships, $nonIncludable));
            if ($offending !== []) {
                throw new InclusionNotAllowed(
                    \array_map(fn(string $name): string => $this->prefixBasePath($transformation->basePath, $name), $offending),
                );
            }
        }
    }

    /**
     * The resource's default-included relationship names, flipped to a set, with
     * any non-includable relation (Capability A) removed so the default cascade
     * never auto-includes a relation that has opted out of inclusion. The
     * non-includable set is resolved against the request, so a relation that is
     * non-includable only for this caller is dropped from the default cascade too.
     *
     * @return array<string, int>
     */
    private function defaultIncludedRelationships(
        SerializerInterface $resource,
        \haddowg\JsonApi\Request\JsonApiRequestInterface $request,
        mixed $object,
    ): array {
        $defaults = $resource->getDefaultIncludedRelationships($object);

        if ($resource instanceof IncludeControlsInterface) {
            $nonIncludable = $resource->getNonIncludableRelationships($request, $object);
            if ($nonIncludable !== []) {
                $defaults = \array_values(\array_diff($defaults, $nonIncludable));
            }
        }

        return \array_flip($defaults);
    }

    private function prefixBasePath(string $basePath, string $name): string
    {
        return ($basePath !== '' ? $basePath . '.' : '') . $name;
    }
}
