<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\RelationshipLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;

/**
 * Base for the serialization-side relationships ({@see ToOneRelationship},
 * {@see ToManyRelationship}) returned from a {@see SerializerInterface}'s
 * relationship callables. Holds the related data, its serializer, optional
 * links and meta, and transforms itself into a JSON:API relationship object,
 * contributing any included resources to the compound-document accumulator.
 *
 * This is a consumer-facing type: resource serializers construct these to
 * describe a resource's relationships. Mutable fluent setters mirror the
 * relationship's role as a builder, not a leaf value object.
 *
 * @see https://jsonapi.org/format/1.1/#document-resource-object-relationships
 */
abstract class AbstractRelationship
{
    /**
     * @var array<string, mixed>
     */
    protected array $meta;

    protected ?RelationshipLinks $links;

    protected mixed $data;

    protected bool $isCallableData;

    protected bool $omitDataWhenNotIncluded;

    protected ?SerializerInterface $resource;

    /**
     * The relation's URI segment when this relationship should emit the spec's
     * conventional `self` / `related` links by convention; `null` when the
     * owning relation opted out (or for a relationship built outside a relation
     * field). The actual URLs are computed in {@see transform()}, the only layer
     * that knows the parent resource's type + id and the configured base URI.
     */
    protected ?string $conventionLinksUriFieldName = null;

    /**
     * Whether the conventional `self` link (the relationship-linkage endpoint) is
     * emitted when {@see withConventionLinks()} is in effect. Suppressed when the
     * owning relation's relationship endpoint is not exposed, so a rendered link
     * never points at a host 404.
     */
    protected bool $conventionLinksSelf = true;

    /**
     * Whether the conventional `related` link (the related endpoint) is emitted
     * when {@see withConventionLinks()} is in effect. Suppressed when the owning
     * relation's related endpoint is not exposed, so a rendered link never points
     * at a host 404.
     */
    protected bool $conventionLinksRelated = true;

    /**
     * @internal
     *
     * @param array<string, mixed> $defaultRelationships
     *
     * @return array<int|string, mixed>|false|null
     */
    abstract protected function transformData(
        ResourceTransformation $transformation,
        ResourceTransformer $resourceTransformer,
        DataInterface $data,
        array $defaultRelationships,
    ): array|false|null;

    /**
     * @param array<string, mixed> $meta
     */
    final public function __construct(
        array $meta = [],
        ?RelationshipLinks $links = null,
        mixed $data = null,
        ?SerializerInterface $resource = null,
    ) {
        $this->meta = $meta;
        $this->links = $links;
        $this->data = $data;
        $this->isCallableData = false;
        $this->omitDataWhenNotIncluded = false;
        $this->resource = $resource;
    }

    /**
     * @return static
     */
    public static function create(): static
    {
        return new static();
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return static
     */
    public static function createWithMeta(array $meta): static
    {
        return new static($meta);
    }

    /**
     * @return static
     */
    public static function createWithLinks(?RelationshipLinks $links): static
    {
        return new static([], $links);
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return static
     */
    public static function createWithData(array $data, SerializerInterface $resource): static
    {
        return new static([], null, $data, $resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return $this
     */
    public function setMeta(array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    public function getLinks(): ?RelationshipLinks
    {
        return $this->links;
    }

    /**
     * @return $this
     */
    public function setLinks(RelationshipLinks $links): static
    {
        $this->links = $links;

        return $this;
    }

    /**
     * Marks this relationship to emit the spec's conventional `self` / `related`
     * links, using `$uriFieldName` as the relationship's URI segment. The URLs
     * are built in {@see transform()} from the owning resource's type + id and
     * the server base URI; an explicit {@see setLinks()} takes precedence.
     *
     * `$exposeSelf` / `$exposeRelated` gate the individual links: pass `false`
     * for either to omit the link to a suppressed endpoint, so a rendered link
     * never points at a host 404.
     *
     * @internal
     *
     * @return $this
     */
    public function withConventionLinks(string $uriFieldName, bool $exposeSelf = true, bool $exposeRelated = true): static
    {
        $this->conventionLinksUriFieldName = $uriFieldName;
        $this->conventionLinksSelf = $exposeSelf;
        $this->conventionLinksRelated = $exposeRelated;

        return $this;
    }

    /**
     * @return $this
     */
    public function setData(mixed $data, SerializerInterface $resource): static
    {
        $this->data = $data;
        $this->isCallableData = false;
        $this->resource = $resource;

        return $this;
    }

    /**
     * @return $this
     */
    public function setDataAsCallable(callable $callableData, SerializerInterface $resource): static
    {
        $this->data = $callableData;
        $this->isCallableData = true;
        $this->resource = $resource;

        return $this;
    }

    /**
     * @return $this
     */
    public function omitDataWhenNotIncluded(): static
    {
        $this->omitDataWhenNotIncluded = true;

        return $this;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $defaultRelationships
     *
     * @return array<string, mixed>|null
     */
    final public function transform(
        ResourceTransformation $transformation,
        ResourceTransformer $resourceTransformer,
        DataInterface $data,
        array $defaultRelationships,
    ): ?array {
        $requestedRelationshipName = $transformation->requestedRelationshipName;
        $currentRelationshipName = $transformation->currentRelationshipName;
        $basePath = $transformation->basePath;

        $isCurrentRelationship = $requestedRelationshipName !== '' && $currentRelationshipName === $requestedRelationshipName;
        $isIncludedField = $transformation->request->isIncludedField($transformation->resourceType, $currentRelationshipName);
        $isIncludedRelationship = $transformation->request->isIncludedRelationship($basePath, $currentRelationshipName, $defaultRelationships);

        // The relationship is not needed at all
        if ($isCurrentRelationship === false && $isIncludedField === false && $isIncludedRelationship === false) {
            return null;
        }

        // Transform the relationship data
        $dataMember = false;
        if (
            ($isCurrentRelationship === true || $isIncludedRelationship === true || $this->omitDataWhenNotIncluded === false) &&
            ($isCurrentRelationship === true || $requestedRelationshipName === '')
        ) {
            $dataMember = $this->transformData($transformation, $resourceTransformer, $data, $defaultRelationships);
        }

        // The relationship field is not included
        if ($isIncludedField === false) {
            return null;
        }

        // Transform the relationship link because the relationship field is included
        $relationshipObject = [];

        $links = $this->links ?? $this->conventionLinks($transformation);
        if ($links !== null) {
            $relationshipObject['links'] = $links->transform();
        }

        if ($this->meta !== []) {
            $relationshipObject['meta'] = $this->meta;
        }

        if ($dataMember !== false) {
            $relationshipObject['data'] = $dataMember;
        }

        return $relationshipObject;
    }

    /**
     * Builds the spec's conventional relationship `links` from the owning
     * resource's context, or returns `null` when convention links are not
     * requested or the parent identity cannot be resolved.
     *
     * `self`    = {baseUri}/{parentType}/{parentId}/relationships/{uriFieldName}
     * `related` = {baseUri}/{parentType}/{parentId}/{uriFieldName}
     *
     * The owning resource's type comes from the parent serializer (falling back
     * to the transformation's resolved type) and its id from that serializer's
     * `getId()`. A missing parent serializer or an empty id (e.g. a not-yet-
     * persisted resource) omits the links rather than emitting a malformed URL.
     *
     * @internal
     */
    protected function conventionLinks(ResourceTransformation $transformation): ?RelationshipLinks
    {
        $uriFieldName = $this->conventionLinksUriFieldName;
        if ($uriFieldName === null) {
            return null;
        }

        $parent = $transformation->resource;
        if ($parent === null) {
            return null;
        }

        // The path segment is the parent's URI type (so a resource whose JSON:API
        // type differs from its URL segment links correctly); a serializer that is
        // not URI-type-aware falls back to its JSON:API type, as before.
        $parentType = $parent instanceof UriTypeAwareInterface
            ? $parent->uriType()
            : $parent->getType($transformation->object);
        if ($parentType === '') {
            $parentType = $transformation->resourceType;
        }

        $parentId = $parent->getId($transformation->object);
        if ($parentType === '' || $parentId === '') {
            return null;
        }

        $base = '/' . $parentType . '/' . $parentId;

        return new RelationshipLinks(
            $transformation->baseUri,
            $this->conventionLinksSelf ? new Link($base . '/relationships/' . $uriFieldName) : null,
            $this->conventionLinksRelated ? new Link($base . '/' . $uriFieldName) : null,
        );
    }

    /**
     * @internal
     *
     * @return mixed
     */
    protected function getData()
    {
        if ($this->isCallableData && \is_callable($this->data)) {
            return ($this->data)($this);
        }

        return $this->data;
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $defaultRelationships
     *
     * @return array<string, mixed>|null
     */
    protected function transformResourceIdentifier(
        ResourceTransformation $transformation,
        ResourceTransformer $resourceTransformer,
        DataInterface $data,
        mixed $object,
        array $defaultRelationships,
    ): ?array {
        $relationshipTransformation = clone $transformation;
        $relationshipTransformation->resourceType = '';
        $relationshipTransformation->resource = $this->resource;
        $relationshipTransformation->object = $object;

        $basePath = $transformation->basePath;
        $basePath .= ($basePath !== '' ? '.' : '') . $relationshipTransformation->currentRelationshipName;
        $relationshipTransformation->basePath = $basePath;

        // The included resource's depth is the segment count of its new basePath.
        // When a max include depth is in effect, descending past it is silently
        // skipped — the linkage identifier is still returned below, only the
        // compound `included` expansion is capped. This halts the default cascade
        // at the cap and guarantees termination of mutual default-include cycles.
        $maxIncludeDepth = $transformation->maxIncludeDepth;
        $withinDepth = $maxIncludeDepth === null || \substr_count($basePath, '.') + 1 <= $maxIncludeDepth;

        if (
            $withinDepth &&
            $transformation->request->isIncludedRelationship(
                $transformation->basePath,
                $transformation->currentRelationshipName,
                $defaultRelationships,
            )
        ) {
            $resource = $resourceTransformer->transformToResourceObject($relationshipTransformation, $data);
            if ($resource !== null) {
                $data->addIncludedResource($resource);
            }
        }

        return $resourceTransformer->transformToResourceIdentifier($relationshipTransformation);
    }
}
