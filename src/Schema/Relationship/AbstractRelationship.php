<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Relationship;

use haddowg\JsonApi\Schema\Data\DataInterface;
use haddowg\JsonApi\Schema\Link\RelationshipLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
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
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
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
    public function transform(
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

        if ($this->links !== null) {
            $relationshipObject['links'] = $this->links->transform();
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

        if (
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
