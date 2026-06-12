<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Hydrator\HydratorInterface;
use haddowg\JsonApi\Hydrator\UpdateRelationshipHydratorInterface;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Serializer\UriTypeAwareInterface;

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
 * the transformer reading {@see \haddowg\JsonApi\Resource\Field\FieldInterface::isSparseField()} and the request, so the
 * resource emits every non-hidden field and lets the engine narrow.
 */
abstract class AbstractResource implements SerializerInterface, HydratorInterface, UpdateRelationshipHydratorInterface, UriTypeAwareInterface, SerializerResolverAwareInterface
{
    use RendersRelationsTrait;

    /**
     * The JSON:API resource type. Subclasses set this.
     */
    public static string $type = '';

    /**
     * The URI path segment for this resource type, used in generated links (and,
     * by hosts, routes) — e.g. `books` for the type `book`. Empty means "use
     * {@see $type}". Subclasses override to decouple the URL segment from the
     * JSON:API type (a plural, or a kebab-cased name).
     */
    public static string $uriType = '';

    protected ?\haddowg\JsonApi\Resource\SerializerResolverInterface $serializerResolver = null;

    /**
     * @var list<\haddowg\JsonApi\Resource\Field\FieldInterface>|null
     */
    private ?array $fieldCache = null;

    /**
     * The resource's field inventory (attributes + relationships).
     *
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    abstract public function fields(): array;

    /**
     * The filters this resource exposes (metadata; execution lives in adapter
     * handlers). Default: none.
     *
     * @return list<\haddowg\JsonApi\Resource\Filter\FilterInterface>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Sorts that don't map directly to a single sortable field. The base
     * already derives a {@see \haddowg\JsonApi\Resource\Sort\SortByField} for
     * every field that declared `->sortable()`; override only to add computed or
     * multi-column sorts.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    public function sorts(): array
    {
        return [];
    }

    /**
     * The pagination strategy for this resource's collections, or `null` for no
     * pagination (the {@see \haddowg\JsonApi\Server\Server} default applies when
     * this returns null).
     */
    public function pagination(): ?\haddowg\JsonApi\Pagination\PaginatorInterface
    {
        return null;
    }

    /**
     * Every sort the resource accepts: the field-derived
     * {@see \haddowg\JsonApi\Resource\Sort\SortByField}s plus any explicit
     * {@see sorts()}. Keyed by sort key (later entries win), returned as a list.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    public function allSorts(): array
    {
        $sorts = [];
        foreach ($this->allFields() as $field) {
            if ($field->isSortable()) {
                $sorts[$field->name()] = \haddowg\JsonApi\Resource\Sort\SortByField::make(
                    $field->name(),
                    $field->column() ?? $field->name(),
                );
            }
        }

        foreach ($this->sorts() as $sort) {
            $sorts[$sort->key()] = $sort;
        }

        return \array_values($sorts);
    }

    /**
     * Injects the resolver relationships use to serialize related resources.
     * The {@see \haddowg\JsonApi\Server\Server} calls this when it hands out the
     * resource; standalone use leaves it null (relationships are then omitted).
     */
    public function setSerializerResolver(\haddowg\JsonApi\Resource\SerializerResolverInterface $resolver): void
    {
        $this->serializerResolver = $resolver;
    }

    public function getType(mixed $object): string
    {
        return static::$type;
    }

    public function uriType(): string
    {
        return static::$uriType !== '' ? static::$uriType : static::$type;
    }

    public function getId(mixed $object): string
    {
        $idField = $this->idField();
        if ($idField === null) {
            return '';
        }

        $value = $idField->serializeWithoutRequest($object);

        return \is_scalar($value) ? (string) $value : '';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        $attributes = [];
        foreach ($this->attributeFields() as $field) {
            $attributes[$field->name()] = static fn(mixed $model, JsonApiRequestInterface $request, string $fieldName): mixed
                => $field->serialize($model, $request, $fieldName);
        }

        return $attributes;
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        $resolver = $this->serializerResolver;
        if ($resolver === null) {
            return [];
        }

        return self::relationshipCallables($this->relationFields(), $resolver);
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
     * Applies a relationship-endpoint mutation
     * (`/{type}/{id}/relationships/{name}`) to `$domainObject`, returning the
     * mutated object. The verb selects the mode — `PATCH` replaces, `POST` adds,
     * `DELETE` removes — and this method enforces both cardinality (add/remove are
     * to-many only) and the relation's mutability flags before applying the
     * storage-agnostic baseline (a scalar column write); a data-layer adapter
     * overrides the apply to mutate the real association.
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws RelationshipNotExists when this resource has no such relationship (404)
     * @throws RelationshipTypeInappropriate when add/remove targets a to-one relationship (400)
     * @throws FullReplacementProhibited when a replace targets a relation that {@see RelationInterface::allowsReplace()} === false (403)
     * @throws AdditionProhibited when an add targets a relation that {@see RelationInterface::allowsAdd()} === false (403)
     * @throws RemovalProhibited when a removal (or a to-one clear) targets a relation that {@see RelationInterface::allowsRemove()} === false (403)
     */
    public function hydrateRelationship(
        string $relationship,
        JsonApiRequestInterface $request,
        mixed $domainObject,
    ): mixed {
        $relation = $this->relationNamed($relationship);
        if ($relation === null) {
            throw new RelationshipNotExists($relationship);
        }

        $mode = match ($request->getMethod()) {
            'POST' => Mode::Add,
            'DELETE' => Mode::Remove,
            default => Mode::Replace,
        };

        // Add / remove are to-many operations: POST / DELETE to a to-one
        // relationship endpoint is a cardinality error.
        if ($mode !== Mode::Replace && $relation->isToMany() === false) {
            throw new RelationshipTypeInappropriate($relationship, 'to-one', 'to-many');
        }

        if ($relation->isToMany()) {
            return $this->mutateToMany($relation, $relationship, $request, $domainObject, $mode);
        }

        return $this->mutateToOne($relation, $relationship, $request, $domainObject);
    }

    /**
     * Applies a `PATCH` to a to-one relationship endpoint: a non-null linkage is a
     * full replacement (gated by {@see RelationInterface::allowsReplace()}); a
     * `data: null` clears it (a removal, gated by
     * {@see RelationInterface::allowsRemove()}).
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws FullReplacementProhibited
     * @throws RemovalProhibited
     */
    protected function mutateToOne(
        RelationInterface $relation,
        string $relationship,
        JsonApiRequestInterface $request,
        mixed $domainObject,
    ): mixed {
        $linkage = $request->getRelationshipLinkageToOne($relationship);

        if ($linkage->isEmpty()) {
            if ($relation->allowsRemove() === false) {
                throw new RemovalProhibited($relationship);
            }
        } elseif ($relation->allowsReplace() === false) {
            throw new FullReplacementProhibited($relationship);
        }

        return $relation->hydrateRelationship($domainObject, $linkage);
    }

    /**
     * Applies a to-many relationship-endpoint mutation under `$mode`: `PATCH`
     * replaces (gated by {@see RelationInterface::allowsReplace()}), `POST` adds
     * (gated by {@see RelationInterface::allowsAdd()}), `DELETE` removes (gated by
     * {@see RelationInterface::allowsRemove()}).
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws FullReplacementProhibited
     * @throws AdditionProhibited
     * @throws RemovalProhibited
     */
    protected function mutateToMany(
        RelationInterface $relation,
        string $relationship,
        JsonApiRequestInterface $request,
        mixed $domainObject,
        Mode $mode,
    ): mixed {
        if ($mode === Mode::Replace && $relation->allowsReplace() === false) {
            throw new FullReplacementProhibited($relationship);
        }

        if ($mode === Mode::Add && $relation->allowsAdd() === false) {
            throw new AdditionProhibited($relationship);
        }

        if ($mode === Mode::Remove && $relation->allowsRemove() === false) {
            throw new RemovalProhibited($relationship);
        }

        $linkage = $request->getRelationshipLinkageToMany($relationship);

        return $relation->applyToMany($domainObject, $linkage, $mode);
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
        if (!\is_array($data)) {
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
     * @throws DataMemberMissing
     * @throws ResourceTypeMissing
     * @throws ResourceTypeUnacceptable
     */
    protected function hydrateForUpdate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $data = $request->getResource();
        if (!\is_array($data)) {
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
            throw new ClientGeneratedIdNotSupported($clientId);
        }

        $column = $idField->column();
        if ($column === null) {
            return $domainObject;
        }

        $id = $clientId !== '' ? $clientId : $this->generateId();

        return Accessor::set($domainObject, $column, $id);
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

            $domainObject = $field->hydrate($domainObject, $attributes[$field->name()], $data, $request, $creating);
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
                if ($request->hasToManyRelationship($relation->name())) {
                    $domainObject = $relation->hydrateRelationship($domainObject, $request->getToManyRelationship($relation->name()));
                }

                continue;
            }

            if ($request->hasToOneRelationship($relation->name())) {
                $domainObject = $relation->hydrateRelationship($domainObject, $request->getToOneRelationship($relation->name()));
            }
        }

        return $domainObject;
    }

    /**
     * The cached field inventory.
     *
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    final protected function allFields(): array
    {
        return $this->fieldCache ??= \array_values($this->fields());
    }

    /**
     * Non-id, non-relation, non-hidden attribute fields.
     *
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    protected function attributeFields(): array
    {
        return \array_values(\array_filter(
            $this->allFields(),
            static fn(\haddowg\JsonApi\Resource\Field\FieldInterface $field): bool => !$field instanceof Id
                && !$field instanceof \haddowg\JsonApi\Resource\Field\RelationInterface
                && !$field->isHidden(),
        ));
    }

    /**
     * The relation field declared under the JSON:API member `$name`, or `null`
     * if this resource has no such (non-hidden) relationship. The single lookup
     * a data-layer adapter needs to drive the related / relationship endpoints:
     * the returned {@see \haddowg\JsonApi\Resource\Field\RelationInterface}
     * answers existence (non-null), cardinality
     * ({@see \haddowg\JsonApi\Resource\Field\RelationInterface::isToMany()}),
     * the related type(s)
     * ({@see \haddowg\JsonApi\Resource\Field\RelationInterface::relatedTypes()}),
     * and reads the related domain value(s) off the parent without serializing
     * ({@see \haddowg\JsonApi\Resource\Field\RelationInterface::readValue()}).
     */
    public function relationNamed(string $name): ?\haddowg\JsonApi\Resource\Field\RelationInterface
    {
        foreach ($this->relationFields() as $relation) {
            if ($relation->name() === $name) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * @return list<\haddowg\JsonApi\Resource\Field\RelationInterface>
     */
    protected function relationFields(): array
    {
        $relations = [];
        foreach ($this->allFields() as $field) {
            if ($field instanceof \haddowg\JsonApi\Resource\Field\RelationInterface && !$field->isHidden()) {
                $relations[] = $field;
            }
        }

        return $relations;
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
