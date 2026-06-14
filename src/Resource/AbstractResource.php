<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\ClientGeneratedIdRequired;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdUndecodable;
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
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SelfLinkAwareInterface;
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
abstract class AbstractResource implements SerializerInterface, HydratorInterface, UpdateRelationshipHydratorInterface, UriTypeAwareInterface, SerializerResolverAwareInterface, IncludeControlsInterface, SelfLinkAwareInterface
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
     * The default sort order applied to a collection of this resource **only when
     * the request carries no `sort` parameter**. An explicit `?sort=` overrides it
     * entirely (the default is not appended to it).
     *
     * Each entry is a {@see \haddowg\JsonApi\Resource\Sort\SortDirective} — the
     * same shape a data layer builds for a requested sort — pairing one
     * {@see \haddowg\JsonApi\Resource\Sort\SortInterface} (any sort the resource
     * exposes, typically a {@see \haddowg\JsonApi\Resource\Sort\SortByField}) with
     * its direction, most significant first. Default: `[]` (no default order, the
     * collection is returned in storage order).
     *
     * A default sort makes an otherwise unsorted collection — and therefore its
     * pagination — deterministic. The directives are applied through the resource's
     * sort handler exactly as a requested sort would be, so a default must name a
     * sort the handler can execute.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortDirective>
     */
    public function defaultSort(): array
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

    /**
     * Emits the spec-recommended by-convention resource `self` link
     * (`{baseUri}/{uriType}/{id}`) by default. Override to return `false` to opt
     * this resource out of the convention self link (a `getLinks()` self still
     * wins regardless).
     */
    public function emitsSelfLink(): bool
    {
        return true;
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

    /**
     * The relationship names this resource has opted out of inclusion for, derived
     * from {@see relationFields()} where the relation declared
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::cannotBeIncluded()}.
     *
     * @return list<string>
     */
    public function getNonIncludableRelationships(mixed $object): array
    {
        $names = [];
        foreach ($this->relationFields() as $relation) {
            if ($relation->isIncludable() === false) {
                $names[] = $relation->name();
            }
        }

        return $names;
    }

    /**
     * No per-resource maximum include depth by default — the server default (if
     * any) applies. Override to cap includes for this resource specifically.
     */
    public function maxIncludeDepth(): ?int
    {
        return null;
    }

    /**
     * No allowed-include-paths whitelist by default — includes are unrestricted
     * (subject to per-relation includability and the max include depth). Override
     * to return the full dotted paths permissible when this resource is the
     * request's primary/root type.
     *
     * @return list<string>|null
     */
    public function getAllowedIncludePaths(): ?array
    {
        return null;
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
     * Sources the new resource's id from two orthogonal axes declared on the
     * {@see Id} field. A client-supplied `data.id` is honoured per the field's
     * client-id policy (default: forbidden); when none is supplied the field's
     * server-side fallback applies (default: store-provided — set nothing and let
     * the store/DB assign the id).
     *
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     *
     * @throws ResourceIdInvalid
     * @throws ClientGeneratedIdNotSupported
     * @throws ClientGeneratedIdRequired
     * @throws ResourceIdUndecodable
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

        $column = $idField->column();

        if ($clientId !== '') {
            if (!$idField->allowsClientId()) {
                throw new ClientGeneratedIdNotSupported($clientId);
            }

            if ($column === null) {
                return $domainObject;
            }

            // An encoder makes the id the wire form of a distinct storage key, so a
            // *client-supplied* id must be decoded back to its storage key before it is
            // set (its serialized id then re-encodes and round-trips). A well-formed but
            // undecodable client id 422s. A generated/closure value is a storage key
            // already and is never decoded.
            $encoder = $idField->encoder();
            if ($encoder !== null) {
                $storageKey = $encoder->decode($clientId);
                if ($storageKey === null) {
                    throw new ResourceIdUndecodable($clientId);
                }

                return Accessor::set($domainObject, $column, $storageKey);
            }

            return Accessor::set($domainObject, $column, $clientId);
        }

        // No client id supplied.
        if ($idField->requiresClientId()) {
            throw new ClientGeneratedIdRequired();
        }

        $generated = $idField->generateIdValue();
        if ($generated === null || $column === null) {
            // Store-provided: set nothing — the persister/DB assigns the id.
            return $domainObject;
        }

        return Accessor::set($domainObject, $column, $generated);
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
