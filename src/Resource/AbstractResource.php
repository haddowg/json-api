<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Field;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Relation;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Schema\Relationship\AbstractRelationship;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * The recommended public surface: a single declaration of a JSON:API resource
 * type's fields that satisfies **both** the serializer
 * ({@see SerializerInterface}) and hydrator ({@see HydratorInterface})
 * contracts. Consumers extend this and implement {@see fields()}; serialization
 * and request→model hydration are derived from the field inventory.
 *
 * For cases the field DSL cannot express, register a custom
 * {@see SerializerInterface} and/or {@see HydratorInterface} alongside the
 * resource — the {@see \haddowg\JsonApi\Server\Server} resolves overrides ahead
 * of the resource fallback.
 *
 * `getAttributes()`/`getRelationships()` return callables (the contract the
 * transformer consumes); sparse-fieldset filtering and inclusion are handled by
 * the transformer reading {@see Field::isSparseField()} and the request, so the
 * resource emits every non-hidden field and lets the engine narrow.
 */
abstract class AbstractResource implements SerializerInterface, HydratorInterface
{
    /**
     * The JSON:API resource type. Subclasses set this.
     */
    public static string $type = '';

    protected ?JsonApiRequestInterface $request = null;

    protected mixed $object = null;

    protected ?SerializerResolver $serializerResolver = null;

    /**
     * @var list<Field>|null
     */
    private ?array $fieldCache = null;

    /**
     * The resource's field inventory (attributes + relationships).
     *
     * @return list<Field>
     */
    abstract public function fields(): array;

    /**
     * Injects the resolver relationships use to serialize related resources.
     * The {@see \haddowg\JsonApi\Server\Server} calls this when it hands out the
     * resource; standalone use leaves it null (relationships still emit their
     * shell, without nested linkage data resolution).
     */
    public function setSerializerResolver(SerializerResolver $resolver): void
    {
        $this->serializerResolver = $resolver;
    }

    public function getType(mixed $object): string
    {
        return static::$type;
    }

    public function getId(mixed $object): string
    {
        $idField = $this->idField();
        if ($idField === null) {
            return '';
        }

        $value = $idField->serialize($object, $this->request ?? throw new \LogicException('No active request.'), $idField->name());

        return \is_scalar($value) ? (string) $value : '';
    }

    public function getMeta(mixed $object): array
    {
        return [];
    }

    public function getLinks(mixed $object): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object): array
    {
        $attributes = [];
        foreach ($this->attributeFields() as $field) {
            $name = $field->name();
            $attributes[$name] = static fn(mixed $model, JsonApiRequestInterface $request, string $fieldName): mixed
                => $field->serialize($model, $request, $fieldName);
        }

        return $attributes;
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object): array
    {
        $resolver = $this->serializerResolver;
        if ($resolver === null) {
            return [];
        }

        $relationships = [];
        foreach ($this->relationFields() as $relation) {
            $relationships[$relation->name()] = static fn(mixed $model, JsonApiRequestInterface $request, string $name): AbstractRelationship
                => $relation->buildRelationship($model, $request, $resolver);
        }

        return $relationships;
    }

    /**
     * @internal
     */
    public function initializeTransformation(JsonApiRequestInterface $request, mixed $object): void
    {
        $this->request = $request;
        $this->object = $object;
    }

    /**
     * @internal
     */
    public function clearTransformation(): void
    {
        $this->request = null;
        $this->object = null;
    }

    public function hydrate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $method = $request->getMethod();
        if ($method === 'POST') {
            return $this->hydrateForCreate($request, $domainObject);
        }

        if ($method === 'PATCH') {
            return $this->hydrateForUpdate($request, $domainObject);
        }

        return $domainObject;
    }

    /**
     * Validates the resource `type` member against this resource's type.
     *
     * @param array<string, mixed> $data
     *
     * @throws ResourceTypeMissing
     * @throws ResourceTypeUnacceptable
     */
    protected function validateType(array $data): void
    {
        if (empty($data['type'])) {
            throw new ResourceTypeMissing();
        }

        $type = $data['type'];
        if (!\is_string($type)) {
            throw new ResourceTypeUnacceptable(\gettype($type), [static::$type]);
        }

        if ($type !== static::$type) {
            throw new ResourceTypeUnacceptable($type, [static::$type]);
        }
    }

    /**
     * Generates a new resource id when the client does not supply one. Defaults
     * to a RFC 4122 v4 UUID; override for a different scheme.
     */
    protected function generateId(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
    }

    /**
     * Whether this resource accepts a client-generated id. Defaults to false
     * (the spec lets a server reject client ids).
     */
    protected function acceptsClientGeneratedId(): bool
    {
        return false;
    }

    /**
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws DataMemberMissing
     * @throws ResourceTypeMissing
     * @throws ResourceTypeUnacceptable
     */
    protected function hydrateForCreate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $data = $request->getResource();
        if ($data === null) {
            throw new DataMemberMissing();
        }

        /** @var array<string, mixed> $data */
        $this->validateType($data);

        $domainObject = $this->hydrateId($domainObject, $data);
        $domainObject = $this->hydrateAttributes($domainObject, $data, $request, true);

        return $this->hydrateRelationships($domainObject, $request, true);
    }

    /**
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws ResourceTypeMissing
     * @throws ResourceTypeUnacceptable
     */
    protected function hydrateForUpdate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $data = $request->getResource();
        if ($data === null) {
            throw new DataMemberMissing();
        }

        /** @var array<string, mixed> $data */
        $this->validateType($data);

        $domainObject = $this->hydrateAttributes($domainObject, $data, $request, false);

        return $this->hydrateRelationships($domainObject, $request, false);
    }

    /**
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     *
     * @throws ResourceIdInvalid
     * @throws ClientGeneratedIdNotSupported
     */
    protected function hydrateId(mixed $domainObject, array $data): mixed
    {
        $idField = $this->idField();
        if ($idField === null) {
            return $domainObject;
        }

        $clientId = '';
        if (!empty($data['id'])) {
            if (!\is_string($data['id'])) {
                throw new ResourceIdInvalid(\gettype($data['id']));
            }
            $clientId = $data['id'];
        }

        if ($clientId !== '' && !$this->acceptsClientGeneratedId()) {
            throw new ClientGeneratedIdNotSupported();
        }

        $id = $clientId !== '' ? $clientId : $this->generateId();
        $column = $idField->column();
        if ($column === null) {
            return $domainObject;
        }

        return Field\Accessor::set($domainObject, $column, $id);
    }

    /**
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     */
    protected function hydrateAttributes(mixed $domainObject, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        $attributes = $data['attributes'] ?? null;
        if (!\is_array($attributes)) {
            return $domainObject;
        }

        foreach ($this->attributeFields() as $field) {
            if ($field->isReadOnly($creating)) {
                continue;
            }

            if (!\array_key_exists($field->name(), $attributes)) {
                continue;
            }

            $domainObject = $field->hydrate($domainObject, $attributes[$field->name()], $data, $request);
        }

        return $domainObject;
    }

    /**
     * @param mixed $domainObject
     * @return mixed
     */
    protected function hydrateRelationships(mixed $domainObject, JsonApiRequestInterface $request, bool $creating): mixed
    {
        foreach ($this->relationFields() as $relation) {
            if ($relation->isReadOnly($creating)) {
                continue;
            }

            if ($relation->isToMany()) {
                if (!$request->hasToManyRelationship($relation->name())) {
                    continue;
                }
                $domainObject = $relation->hydrateRelationship($domainObject, $request->getToManyRelationship($relation->name()));

                continue;
            }

            if (!$request->hasToOneRelationship($relation->name())) {
                continue;
            }
            $domainObject = $relation->hydrateRelationship($domainObject, $request->getToOneRelationship($relation->name()));
        }

        return $domainObject;
    }

    /**
     * The cached field inventory.
     *
     * @return list<Field>
     */
    final protected function allFields(): array
    {
        return $this->fieldCache ??= \array_values([...$this->fields()]);
    }

    /**
     * Non-id, non-relation, non-hidden attribute fields.
     *
     * @return list<Field>
     */
    protected function attributeFields(): array
    {
        return \array_values(\array_filter(
            $this->allFields(),
            static fn(Field $field): bool => !$field instanceof Id
                && !$field instanceof Relation
                && !$field->isHidden(),
        ));
    }

    /**
     * @return list<Relation>
     */
    protected function relationFields(): array
    {
        return \array_values(\array_filter(
            $this->allFields(),
            static fn(Field $field): bool => $field instanceof Relation && !$field->isHidden(),
        ));
    }

    protected function idField(): ?Id
    {
        foreach ($this->allFields() as $field) {
            if ($field instanceof Id) {
                return $field;
            }
        }

        return null;
    }
}
