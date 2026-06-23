<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\ClientGeneratedIdRequired;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\RelatedAttributeOwnerMissing;
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
abstract class AbstractResource implements SerializerInterface, HydratorInterface, UpdateRelationshipHydratorInterface, UriTypeAwareInterface, SerializerResolverAwareInterface, IncludeControlsInterface, \haddowg\JsonApi\Serializer\CountableControlsInterface, \haddowg\JsonApi\Serializer\CountableSelfInterface, SelfLinkAwareInterface, \haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface, \haddowg\JsonApi\Serializer\DeclaresEagerLoadsInterface, \haddowg\JsonApi\Serializer\DeclaresRelationsInterface
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
     * Whether the primary collection is client-countable via `?withCount=_self_`,
     * set by {@see countable()}. Off by default — counting is opt-in.
     */
    private bool $isCountable = false;

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
     * A zero-ceremony default `page[size]` for this resource's primary collection.
     * When set, the base {@see pagination()} returns a page-based paginator with this
     * default size (capped by {@see $maxPerPage} when also set) instead of the server
     * default — the common case without writing a paginator. Leave `null` to inherit
     * the server default. Overriding {@see pagination()} directly replaces this
     * shorthand entirely (the explicit strategy wins).
     */
    protected ?int $perPage = null;

    /**
     * The maximum `page[size]` a client may request, applied only when {@see $perPage}
     * drives the paginator. `null` leaves the paginator's own default cap in place.
     */
    protected ?int $maxPerPage = null;

    /**
     * The pagination strategy for this resource's collections. The return value is
     * the **single source of truth** — used verbatim, with `null` meaning *no
     * pagination* (the collection is fetched whole). The base implementation returns a
     * page-based paginator when {@see $perPage} is set, otherwise the `$serverDefault`
     * argument (the {@see \haddowg\JsonApi\Server\Server}'s resolved default paginator,
     * or `null` when the server has none), so a resource:
     *
     * - sets `$perPage` → page-based with that default size (and `$maxPerPage` cap);
     * - returns `$serverDefault` (or don't override, no `$perPage`) → inherit the server default;
     * - returns a paginator → pin that strategy for this resource;
     * - returns `null` → no pagination, fetch-all (renders `meta.total` unconditionally).
     */
    public function pagination(?\haddowg\JsonApi\Pagination\PaginatorInterface $serverDefault): ?\haddowg\JsonApi\Pagination\PaginatorInterface
    {
        if ($this->perPage !== null) {
            $paginator = \haddowg\JsonApi\Pagination\PagePaginator::make()->withDefaultPerPage($this->perPage);

            return $this->maxPerPage !== null ? $paginator->withMaxPerPage($this->maxPerPage) : $paginator;
        }

        return $serverDefault;
    }

    /**
     * Declares this resource's primary collection **countable**: a client may then
     * request its total via `?withCount=_self_` (under the negotiated Countable
     * profile), which renders `meta.total` (and, when paginated, `meta.page.total`
     * + the `last` link). Off by default — counting is opt-in; an unrequested
     * collection paginates count-free. Mirrors {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::countable()}
     * for relations. Fluent: returns `$this`.
     *
     * @return static
     */
    public function countable(): static
    {
        $this->isCountable = true;

        return $this;
    }

    /**
     * Whether this resource's primary collection is countable via
     * `?withCount=_self_` (see {@see countable()}). Off by default.
     */
    public function isCountable(): bool
    {
        return $this->isCountable;
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

    public function serializerResolver(): ?\haddowg\JsonApi\Resource\SerializerResolverInterface
    {
        return $this->serializerResolver;
    }

    public function getType(mixed $object): string
    {
        return static::$type;
    }

    public function uriType(): string
    {
        return static::$uriType !== '' ? static::$uriType : static::$type;
    }

    /**
     * An optional human-readable description for this resource's **resource object**,
     * surfaced on its OpenAPI component schema. Returning `null` (the default) lets
     * the generator emit a sensible generated default naming the type. Override to
     * supply your own.
     *
     * Consumed by the bundle's OpenAPI metadata source, not core's projector (core
     * projects from {@see \haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface}).
     */
    public function getDescription(): ?string
    {
        return null;
    }

    /**
     * An optional human-readable description for one of this resource's CRUD
     * operations, surfaced on that operation in the generated OpenAPI document.
     * Returning `null` (the default) lets the generator emit a sensible generated
     * default describing the operation. Override to supply your own per operation.
     *
     * Consumed by the bundle's OpenAPI metadata source, not core's projector.
     */
    public function describeOperation(\haddowg\JsonApi\OpenApi\Metadata\OperationType $op): ?string
    {
        return null;
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

    /**
     * Every declared field name (the full member namespace) for strict
     * `fields[type]` sparse-fieldset member validation: every name from the field
     * inventory — attributes AND relationships, request-independent and
     * **unfiltered** by visibility, so hidden / write-only / conditionally-hidden
     * / non-sparse fields and `id` are all included. A `fields[type]` member is
     * therefore "unknown" only when it names no declared field at all.
     *
     * @return list<string>
     */
    public function declaredFieldNames(): array
    {
        return \array_values(\array_map(
            static fn(\haddowg\JsonApi\Resource\Field\FieldInterface $field): string => $field->name(),
            $this->allFields(),
        ));
    }

    /**
     * The dedup set of every flattened (`on()`) attribute's backing relation chain
     * — the to-one relation paths a host eager-loads before serializing this
     * resource. Load-not-render: never expanded into `included`. Order is preserved
     * (field-declared `on()` paths, in field order), deduplicated. Each entry is a
     * `.`-separated chain of declared to-one relations (`'author'` or
     * `'publisher.country'`).
     *
     * @return list<string>
     */
    public function eagerLoadRelationshipPaths(): array
    {
        $paths = [];
        foreach ($this->allFields() as $field) {
            $relatedVia = $field->relatedVia();
            if ($relatedVia !== null && !\in_array($relatedVia, $paths, true)) {
                $paths[] = $relatedVia;
            }
        }

        return $paths;
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
            // A write-only field is accepted on write but never rendered: skip it
            // here, alongside the sparse-fieldset filtering, so it appears on no
            // read and a fields[type] parameter naming it cannot resurrect it.
            // The request-aware resolver also skips a field whose write-only state
            // is a per-request predicate (attributeFields() filters only the
            // *unconditionally* hidden, so a conditionally-hidden field flows here
            // and isHiddenFor() gates it against the request + model).
            if ($field->isWriteOnlyFor($request) || $field->isHiddenFor($request, $object)) {
                continue;
            }

            // A flattened attribute (`on('publisher.country')`) reads its backing
            // member off the FINAL related model in a to-one chain, not the owning
            // one: walk the chain hop by hop (each hop honouring its relation's
            // column()/storedAs()) and hand the final related object to the field's
            // serialize() — any intermediate null short-circuits the whole chain to a
            // null value.
            $relatedVia = $field->relatedVia();
            if ($relatedVia !== null) {
                $attributes[$field->name()] = function (mixed $model, JsonApiRequestInterface $request, string $fieldName) use ($field, $relatedVia): mixed {
                    $owner = $this->resolveRelatedChain($field->name(), $relatedVia, $model, $request);

                    return $owner === null ? null : $field->serialize($owner, $request, $fieldName);
                };

                continue;
            }

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
    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array
    {
        $names = [];
        foreach ($this->relationFields() as $relation) {
            if ($relation->isIncludableFor($request, $object) === false) {
                $names[] = $relation->name();
            }
        }

        return $names;
    }

    /**
     * The relationship names this resource declares countable, derived from
     * {@see relationFields()} where the relation is to-many and declared
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::countable()}. A
     * `?withCount` naming any other relationship is rejected (400).
     *
     * @return list<string>
     */
    public function getCountableRelationships(mixed $object): array
    {
        $names = [];
        foreach ($this->relationFields() as $relation) {
            if ($relation->isToMany() && $relation->isCountable()) {
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

        // relationFields() filters only the *unconditionally* hidden relations, so
        // a relation whose hidden state is a per-request predicate still backs the
        // build-time relationNamed() lookup (a conditionally-hidden relation must
        // resolve, else a cannotReplaceFor 403 would degrade to a 404). It is
        // excluded from the *rendered* relationships here, against the request.
        $visible = \array_values(\array_filter(
            $this->relationFields(),
            fn(RelationInterface $relation): bool => !$relation->isHiddenFor($request, $object),
        ));

        return self::relationshipCallables($visible, $resolver);
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
        $linkage = $request->getRelationshipDataToOne($relationship);

        if ($linkage->isEmpty()) {
            if ($relation->allowsRemoveFor($request, $domainObject) === false) {
                throw new RemovalProhibited($relationship);
            }
        } elseif ($relation->allowsReplaceFor($request, $domainObject) === false) {
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
        if ($mode === Mode::Replace && $relation->allowsReplaceFor($request, $domainObject) === false) {
            throw new FullReplacementProhibited($relationship);
        }

        if ($mode === Mode::Add && $relation->allowsAddFor($request, $domainObject) === false) {
            throw new AdditionProhibited($relationship);
        }

        if ($mode === Mode::Remove && $relation->allowsRemoveFor($request, $domainObject) === false) {
            throw new RemovalProhibited($relationship);
        }

        $linkage = $request->getRelationshipDataToMany($relationship);

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
        $domainObject = $this->hydrateRelationships($domainObject, $request, true);

        // Flattened (`on()`) attributes hydrate LAST: a relation associated in this
        // same request body must be visible so the value can be written onto the
        // freshly resolved related model.
        return $this->hydrateRelatedAttributes($domainObject, $data, $request, true);
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
        $domainObject = $this->hydrateRelationships($domainObject, $request, false);

        // Flattened (`on()`) attributes hydrate LAST (see hydrateForCreate()).
        return $this->hydrateRelatedAttributes($domainObject, $data, $request, false);
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
            // Flattened (`on()`) attributes hydrate in a later pass, after
            // relationships — see hydrateRelatedAttributes().
            if ($field->relatedVia() !== null) {
                continue;
            }

            if ($field->isReadOnlyFor($creating, $request)) {
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
     * Hydrates the flattened (`on()`) attributes — those whose value lives on the
     * final related model of a declared, to-one relation chain. Runs **after**
     * {@see hydrateRelationships()} so a relation associated in the same request
     * body is visible. For each present, writable flattened attribute it walks the
     * chain to its final related model (each hop honouring the relation's
     * `column()`/`storedAs()`) and writes the deserialized value onto it (the
     * related entity is mutated in place — Doctrine's UoW persists the dirty loaded
     * entity on flush, the in-memory store shares the reference; no related-persister
     * change). Any null hop in the chain is a 422
     * ({@see RelatedAttributeOwnerMissing}, require-exists): a flattened attribute
     * never auto-instantiates a related model.
     *
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     *
     * @throws RelatedAttributeOwnerMissing
     */
    protected function hydrateRelatedAttributes(mixed $domainObject, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        $attributes = $data['attributes'] ?? null;
        if (!\is_array($attributes)) {
            return $domainObject;
        }

        foreach ($this->attributeFields() as $field) {
            $relatedVia = $field->relatedVia();
            if ($relatedVia === null) {
                continue;
            }

            if ($field->isReadOnlyFor($creating, $request)) {
                continue;
            }

            if (!\array_key_exists($field->name(), $attributes)) {
                continue;
            }

            $owner = $this->resolveRelatedChain($field->name(), $relatedVia, $domainObject, $request);
            if ($owner === null) {
                throw new RelatedAttributeOwnerMissing($field->name(), $relatedVia);
            }

            // The field writes its own backing member (column() ?? name()) onto the
            // final related model and returns it; the parent's association to that
            // related model is unchanged, so we keep $domainObject (only $owner is
            // mutated).
            $field->hydrate($owner, $attributes[$field->name()], $data, $request, $creating);
        }

        return $domainObject;
    }

    /**
     * Walks a flattened (`on()`) attribute's to-one relation chain `model -> seg1
     * -> … -> segN`, returning the **final** related model (the object whose
     * `column() ?? name()` the field reads/writes) or `null` when any intermediate
     * hop is null (short-circuit). Each segment is resolved against the owning
     * type's **hidden-inclusive** relation set (a flattened attribute may back onto
     * a {@see \haddowg\JsonApi\Resource\Field\AbstractRelation::hidden()} relation —
     * the idiomatic internal association) and read via its `readValue()` (honouring
     * its `column()`/`storedAs()`); each successive segment is resolved on the prior
     * segment's related type's serializer, resolved through the injected
     * {@see SerializerResolverInterface}.
     *
     * Every segment must be a declared, to-one relation — enforced fail-loud at
     * boot / container warm-up by the host's eager-load validator. This runtime walk
     * enforces the same invariants as a defence-in-depth `\LogicException` (an
     * unknown segment, a to-many segment, or a chain that cannot be followed because
     * a related serializer is unresolvable), so a misconfiguration never silently
     * mis-reads.
     *
     * @return mixed the final related model, or `null` on any intermediate-null hop
     *
     * @throws \LogicException when a segment is undeclared, to-many, or its related
     *                         type cannot be resolved to continue the walk
     */
    private function resolveRelatedChain(string $attribute, string $path, mixed $model, JsonApiRequestInterface $request): mixed
    {
        $segments = \explode('.', $path);
        $current = $model;
        $currentSerializer = $this;

        foreach ($segments as $index => $segment) {
            $relation = $currentSerializer->relationNamedIncludingHidden($segment);
            if ($relation === null) {
                throw new \LogicException(\sprintf(
                    'Attribute "%s" is flattened via on("%s"), but segment "%s" names no '
                    . 'declared relation.',
                    $attribute,
                    $path,
                    $segment,
                ));
            }

            if ($relation->isToMany()) {
                throw new \LogicException(\sprintf(
                    'Attribute "%s" is flattened via on("%s"), but segment "%s" is '
                    . 'to-many; on() flattens a scalar from a to-one chain — a to-many '
                    . 'is not flattenable, use ?include.',
                    $attribute,
                    $path,
                    $segment,
                ));
            }

            $current = $relation->readValue($current, $request);
            if ($current === null) {
                return null;
            }

            // Past the final segment there is nothing further to resolve; the
            // current related model is the object the field reads/writes.
            if ($index === \count($segments) - 1) {
                break;
            }

            $currentSerializer = $this->serializerForRelatedChain($attribute, $path, $segment, $relation);
        }

        return $current;
    }

    /**
     * Resolves the serializer for the next hop of an `on()` chain: the single
     * declared related type of `$relation`, looked up through the injected
     * {@see SerializerResolverInterface}. The resolved serializer must itself
     * declare a relation inventory (an {@see AbstractResource}, in practice) so the
     * remaining segments can be resolved on it.
     *
     * @throws \LogicException when the relation's related type cannot be resolved to
     *                         a relation-declaring serializer (unregistered, bare, or
     *                         polymorphic — none of which can back an `on()` chain)
     */
    private function serializerForRelatedChain(
        string $attribute,
        string $path,
        string $segment,
        RelationInterface $relation,
    ): \haddowg\JsonApi\Serializer\DeclaresRelationsInterface {
        $resolver = $this->serializerResolver;
        $relatedTypes = $relation->relatedTypes();
        $nextType = $relatedTypes[0] ?? null;

        if ($resolver === null || $nextType === null || \count($relatedTypes) !== 1 || !$resolver->hasSerializerFor($nextType)) {
            throw new \LogicException(\sprintf(
                'Attribute "%s" is flattened via on("%s"), but segment "%s"\'s related '
                . 'type cannot be resolved to continue the chain (a polymorphic or '
                . 'unregistered relation cannot back an on() chain).',
                $attribute,
                $path,
                $segment,
            ));
        }

        $next = $resolver->serializerFor($nextType);
        if (!$next instanceof \haddowg\JsonApi\Serializer\DeclaresRelationsInterface) {
            throw new \LogicException(\sprintf(
                'Attribute "%s" is flattened via on("%s"), but segment "%s"\'s related '
                . 'type "%s" declares no relation inventory to continue the chain.',
                $attribute,
                $path,
                $segment,
                $nextType,
            ));
        }

        return $next;
    }

    /**
     * @param mixed $domainObject
     * @return mixed
     */
    protected function hydrateRelationships(mixed $domainObject, JsonApiRequestInterface $request, bool $creating): mixed
    {
        foreach ($this->relationFields() as $relation) {
            if ($relation->isReadOnlyFor($creating, $request)) {
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
     * The cached field inventory. Guards the reserved `?withCount` token `_self_`
     * at build time: a relation literally named `_self_` would be ambiguous with the
     * token naming the primary collection, so it is rejected here (the single point
     * where every field is first indexed) rather than letting the token silently win.
     *
     * @return list<\haddowg\JsonApi\Resource\Field\FieldInterface>
     */
    final protected function allFields(): array
    {
        if ($this->fieldCache === null) {
            $this->fieldCache = \array_values($this->fields());
            foreach ($this->fieldCache as $field) {
                if ($field instanceof \haddowg\JsonApi\Resource\Field\RelationInterface
                    && $field->name() === \haddowg\JsonApi\Schema\Profile\CountableProfile::SELF_TOKEN) {
                    throw new \LogicException(\sprintf(
                        'Relationship "%s" uses the reserved "?withCount" token; a relation cannot be named "%s".',
                        \haddowg\JsonApi\Schema\Profile\CountableProfile::SELF_TOKEN,
                        \haddowg\JsonApi\Schema\Profile\CountableProfile::SELF_TOKEN,
                    ));
                }
            }
        }

        return $this->fieldCache;
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
     * The declared relation named `$name` **including hidden relations** — the
     * hidden-inclusive twin of {@see relationNamed()} (which filters hidden out).
     * A flattened (`on()`) attribute idiomatically names a `hidden()` "internal
     * association", so the eager-load walk and its validation must still find it.
     */
    public function relationNamedIncludingHidden(string $name): ?\haddowg\JsonApi\Resource\Field\RelationInterface
    {
        foreach ($this->allFields() as $field) {
            if ($field instanceof \haddowg\JsonApi\Resource\Field\RelationInterface && $field->name() === $name) {
                return $field;
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
