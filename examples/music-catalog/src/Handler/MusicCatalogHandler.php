<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Handler;

use haddowg\JsonApi\Examples\MusicCatalog\Data\InMemoryRepository;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Album;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Artist;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Favorite;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Library;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Playlist;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\Track;
use haddowg\JsonApi\Examples\MusicCatalog\Domain\User;
use haddowg\JsonApi\Examples\MusicCatalog\Exception\PaymentRequired;
use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Pagination\PageInterface;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;

/**
 * The example app's single {@see \haddowg\JsonApi\Operation\OperationHandlerInterface}:
 * one `match (true)` over all nine concrete operation value objects, dispatching
 * each to the {@see InMemoryRepository}. It is the worked referent for the
 * operations / responses / related-endpoints / relationship-mutation docs pages.
 *
 * Every arm reaches the serializer / hydrator registry through the
 * {@see OperationContext::$server} (narrowed to the concrete {@see Server}, which
 * carries `resourceFor()` / `defaultPaginator()` beyond the resolving interface).
 * Reads render {@see DataResponse} / {@see RelatedResponse} / {@see IdentifierResponse};
 * writes drive the per-type hydrator and render `201` (+`Location`) / `200` / `204`.
 * The relationship-mutation arms delegate to core's
 * {@see AbstractResource::hydrateRelationship()}, which maps the verb to a
 * {@see \haddowg\JsonApi\Resource\Field\Mode}, enforces cardinality + the
 * `cannotReplace()`/`cannotAdd()`/`cannotRemove()` flags, and throws the typed
 * `400`/`403`s — so the handler need not catch them: every JSON:API exception
 * propagates to the {@see \haddowg\JsonApi\Middleware\ErrorHandlerMiddleware} and is
 * rendered as the right status (the same path the demonstration
 * {@see PaymentRequired} `402` takes).
 *
 * The handler never dictates instantiation: {@see newInstanceFor()} is a tiny
 * type → `new Album()` map (plain constructors with defaulted props), so the
 * library imposes no factory contract on the domain.
 */
final class MusicCatalogHandler implements \haddowg\JsonApi\Operation\OperationHandlerInterface
{
    public function __construct(private readonly InMemoryRepository $repository) {}

    public function handle(
        JsonApiOperationInterface $operation,
    ): DataResponse|RelatedResponse|IdentifierResponse|NoContentResponse|ErrorResponse {
        return match (true) {
            $operation instanceof FetchResourceOperation => $this->fetch($operation),
            $operation instanceof FetchRelatedOperation => $this->fetchRelated($operation),
            $operation instanceof FetchRelationshipOperation => $this->fetchRelationship($operation),
            $operation instanceof CreateResourceOperation => $this->create($operation),
            $operation instanceof UpdateResourceOperation => $this->update($operation),
            $operation instanceof DeleteResourceOperation => $this->delete($operation),
            $operation instanceof UpdateRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Replace),
            $operation instanceof AddToRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Add),
            $operation instanceof RemoveFromRelationshipOperation => $this->mutateRelationship($operation, $operation->body(), Mode::Remove),
            default => ErrorResponse::fromException(new ResourceNotFound()),
        };
    }

    // --- Reads ---------------------------------------------------------------

    /**
     * `GET /{type}` (collection) or `GET /{type}/{id}` (single). A single fetch
     * maps a missing resource to a `404`; a collection resolves the resource's
     * paginator (`resource → server default`), runs the repository's
     * window → slice → count → paginate loop, and renders a paginated
     * {@see DataResponse::fromPage()} (else a plain
     * {@see DataResponse::fromCollection()}).
     */
    private function fetch(FetchResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $serializer = $server->serializerFor($type);

        $id = $operation->target()->id;
        if ($id !== null) {
            $model = $this->repository->fetchOne($type, $id);
            if ($model === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            return DataResponse::fromResource($model, $serializer);
        }

        // A standalone bare serializer (the read-only `charts` type) has no
        // Resource — so no filters/sorts/pagination metadata. Its collection is the
        // raw stored list, served straight through the serializer.
        if (!$server->hasResourceFor($type)) {
            return DataResponse::fromCollection($this->repository->fetchAll($type), $serializer);
        }

        $resource = $server->resourceFor($type);
        $request = $this->request($operation->context());
        $paginator = $resource->pagination($server->defaultPaginator());

        $result = $this->repository->fetchCollection(
            $type,
            $resource,
            $operation->queryParameters(),
            $request,
            $paginator,
        );

        if ($result instanceof PageInterface) {
            return DataResponse::fromPage($result, $serializer);
        }

        return DataResponse::fromCollection($result, $serializer);
    }

    /**
     * `GET /{type}/{id}/{relationship}` — the related-resource(s) document. Loads
     * the parent, resolves the named relation (a `404` when unknown or its related
     * endpoint is suppressed), reads the related value off the parent, and renders
     * through the related type's serializer:
     *  - a to-one resolves the serializer **from the related object** so a
     *    polymorphic to-one ({@see \haddowg\JsonApi\Resource\Field\MorphTo}) renders
     *    the object's own type; an empty to-one renders `data: null`;
     *  - a to-many resolves the per-relation paginator
     *    (`relation → related resource → server default`) and renders a paginated
     *    {@see RelatedResponse::fromPage()} (else a plain collection). A polymorphic
     *    to-many ({@see \haddowg\JsonApi\Resource\Field\MorphToMany}) renders its
     *    mixed members through a {@see PolymorphicSerializer} and carries no shared
     *    filter/sort vocabulary, so a `filter`/`sort` on it is a `400` and only
     *    `page` slices.
     */
    private function fetchRelated(FetchRelatedOperation $operation): RelatedResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $server->resourceFor($type)->relationNamed($relationshipName);
        if ($relation === null || !$relation->exposesRelatedEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        $request = $this->request($operation->context());
        $related = $relation->readValue($parent, $request);

        $relatedTypes = $relation->relatedTypes();
        $polymorphic = \count($relatedTypes) > 1;
        $relatedType = $relatedTypes[0] ?? $type;

        if ($relation->isToMany()) {
            $relatedResource = $polymorphic ? null : $server->resourceFor($relatedType);

            // A polymorphic to-many has no shared filter/sort vocabulary: those
            // 400. Only `page` windows the mixed members.
            if ($polymorphic) {
                $unsupported = match (true) {
                    $operation->queryParameters()->filter !== [] => 'filter',
                    $operation->queryParameters()->sort !== [] => 'sort',
                    default => null,
                };
                if ($unsupported !== null) {
                    return ErrorResponse::fromException(new \haddowg\JsonApi\Exception\QueryParamUnrecognized($unsupported));
                }
            }

            $fallback = $relatedResource?->pagination($server->defaultPaginator())
                ?? $server->defaultPaginator();
            $paginator = $relation->pagination($fallback);

            /** @var iterable<object> $related */
            $result = $this->repository->fetchRelatedCollection(
                $related,
                $relatedResource,
                $operation->queryParameters(),
                $request,
                $paginator,
                applyCriteria: !$polymorphic,
            );

            $serializer = $polymorphic
                ? $this->polymorphicSerializer($relation, $server)
                : $server->serializerFor($relatedType);

            if ($result instanceof PageInterface) {
                return RelatedResponse::fromPage($result, $serializer);
            }

            return RelatedResponse::fromCollection($result, $serializer);
        }

        // Resolve the to-one serializer from the actual related object so a
        // polymorphic to-one renders the object's own type; a null related value
        // falls back to the first registered serializer and renders `data: null`.
        $serializer = $relation->resolveSerializer($related, $server) ?? $server->serializerFor($relatedType);

        return RelatedResponse::fromResource($related, $serializer);
    }

    /**
     * `GET /{type}/{id}/relationships/{relationship}` — the linkage document
     * (resource identifiers only). Routes the parent through the *parent* type's
     * serializer with the relationship name set so the transformer emits linkage.
     * A relation whose relationship endpoint is suppressed is enforced as a `404`.
     */
    private function fetchRelationship(FetchRelationshipOperation $operation): IdentifierResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $server->resourceFor($type)->relationNamed($relationshipName);
        if ($relation === null || !$relation->exposesRelationshipEndpoint()) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        return IdentifierResponse::forRelationship($parent, $server->serializerFor($type), $relationshipName);
    }

    // --- Writes --------------------------------------------------------------

    /**
     * `POST /{type}` — create. Hydrates a fresh domain object from the request body
     * via the per-type hydrator, persists it, and renders `201` with a `Location`
     * header built from the resource's URI segment ({@see AbstractResource::uriType()}).
     *
     * The demonstration {@see PaymentRequired} `402` is thrown here for a private
     * playlist created without the `premium` flag, so a custom exception flows
     * through the same error path as the built-in catalogue.
     */
    private function create(CreateResourceOperation $operation): DataResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $body = $operation->body();

        $this->guardPremium($type, $body);

        $serializer = $server->serializerFor($type);

        // The body's relationships are object references, not scalar ids, so they
        // are stripped before the per-type hydrator runs (which would otherwise
        // assign a scalar id to a typed association property) and applied afterward
        // through the same resolve-id→object→set seam the relationship endpoints use.
        $relationships = $this->bodyRelationships($server, $type, $body, creating: true);

        $entity = $server->hydratorFor($type)->hydrate($this->withoutRelationships($body), $this->newInstanceFor($type));
        \assert(\is_object($entity));

        $this->applyBodyRelationships($entity, $relationships);

        // A store-provided id (the resource's `Id::make()` is neither `generated()`
        // nor client-supplied, e.g. `users`) is empty after hydration: the store
        // mints one, the way a database auto-increment column would, and it is set
        // on the entity so the rendered resource and the `Location` carry it.
        $id = $serializer->getId($entity);
        if ($id === '') {
            $id = $this->repository->nextId($type);
            Accessor::set($entity, 'id', $id);
        }

        $this->repository->create($type, $entity, $id);

        $uriType = $server->resourceFor($type)->uriType();

        return DataResponse::fromResource($entity, $serializer)
            ->withStatus(201)
            ->withHeader('Location', $server->baseUri() . '/' . $uriType . '/' . $id);
    }

    /**
     * `PATCH /{type}/{id}` — update. Loads the target (a `404` when absent),
     * hydrates the body's attributes onto it (PATCH absence = no change), replaces
     * the named associations through the relationship seam, persists, and renders
     * `200`.
     */
    private function update(UpdateResourceOperation $operation): DataResponse|ErrorResponse
    {
        $server = $this->server($operation->context());
        $type = $operation->target()->type;
        $id = $operation->target()->id;
        $body = $operation->body();

        $entity = $id !== null ? $this->repository->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $serializer = $server->serializerFor($type);

        // As for create: hydrate attributes via the per-type hydrator, then replace
        // the associations named in `data.relationships` through the seam.
        $relationships = $this->bodyRelationships($server, $type, $body, creating: false);

        $entity = $server->hydratorFor($type)->hydrate($this->withoutRelationships($body), $entity);
        \assert(\is_object($entity));

        $this->applyBodyRelationships($entity, $relationships);

        $this->repository->update($type, $entity, (string) $id);

        return DataResponse::fromResource($entity, $serializer);
    }

    /**
     * Collects the writable relationships present in a write body's
     * `data.relationships` member, each paired with its parsed linkage, so the
     * handler can apply them through the object-graph seam after the hydrator fills
     * the attributes. A relationship that is unknown, or read-only for this
     * operation, is skipped — the read-only gate mirrors core's
     * `AbstractResource::hydrateRelationships()`, which never sees these because the
     * body is stripped of relationships before hydration.
     *
     * @return list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}>
     */
    private function bodyRelationships(Server $server, string $type, JsonApiRequestInterface $body, bool $creating): array
    {
        $resource = $server->resourceFor($type);

        $collected = [];
        foreach ($this->bodyRelationshipNames($body) as $name) {
            $relation = $resource->relationNamed($name);
            if ($relation === null || $relation->isReadOnly($creating)) {
                continue;
            }

            if ($relation->isToMany()) {
                if ($body->hasToManyRelationship($name)) {
                    $collected[] = ['relation' => $relation, 'linkage' => $body->getToManyRelationship($name)];
                }

                continue;
            }

            if ($body->hasToOneRelationship($name)) {
                $collected[] = ['relation' => $relation, 'linkage' => $body->getToOneRelationship($name)];
            }
        }

        return $collected;
    }

    /**
     * Applies each collected relationship to the hydrated entity through the
     * object-graph seam in {@see Mode::Replace}, resolving the linkage ids to the
     * stored related objects and setting the association.
     *
     * @param list<array{relation: RelationInterface, linkage: ToOneRelationship|ToManyRelationship}> $relationships
     */
    private function applyBodyRelationships(object $entity, array $relationships): void
    {
        foreach ($relationships as $relationship) {
            $this->applyRelationship($entity, $relationship['relation'], $relationship['linkage'], Mode::Replace);
        }
    }

    /**
     * The relationship names present in the write body's `data.relationships`
     * member, or an empty list when the document carries none.
     *
     * @return list<string>
     */
    private function bodyRelationshipNames(JsonApiRequestInterface $body): array
    {
        $data = $body->getResource();
        if (!\is_array($data)) {
            return [];
        }

        $relationships = $data['relationships'] ?? null;
        if (!\is_array($relationships)) {
            return [];
        }

        /** @var list<string> $names */
        $names = \array_values(\array_filter(\array_keys($relationships), '\is_string'));

        return $names;
    }

    /**
     * A copy of the write body with `data.relationships` removed, so the per-type
     * hydrator hydrates only the id + attributes and never assigns a scalar linkage
     * id to a typed association property — the handler applies the associations
     * through the object-graph seam instead.
     */
    private function withoutRelationships(JsonApiRequestInterface $body): JsonApiRequestInterface
    {
        /** @var array<string, mixed> $document */
        $document = (array) $body->getParsedBody();
        $data = $document['data'] ?? null;
        if (!\is_array($data) || !isset($data['relationships'])) {
            return $body;
        }

        unset($data['relationships']);
        $document['data'] = $data;

        $stripped = $body->withParsedBody($document);
        \assert($stripped instanceof JsonApiRequestInterface);

        return $stripped;
    }

    /**
     * `DELETE /{type}/{id}` — delete. Loads the target (a `404` when absent),
     * removes it, and renders an empty `204`.
     */
    private function delete(DeleteResourceOperation $operation): NoContentResponse|ErrorResponse
    {
        $type = $operation->target()->type;
        $id = $operation->target()->id;

        $entity = $id !== null ? $this->repository->fetchOne($type, $id) : null;
        if ($entity === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $this->repository->delete($type, (string) $id);

        return NoContentResponse::create();
    }

    /**
     * `PATCH`/`POST`/`DELETE /{type}/{id}/relationships/{relationship}` — replace /
     * add / remove. One shape: load the parent (a `404` when absent), resolve the
     * named relation (a `404` when unknown), enforce cardinality (add/remove are
     * to-many only → {@see RelationshipTypeInappropriate} `400`) and the
     * `cannotReplace()`/`cannotAdd()`/`cannotRemove()` mutability flags (the typed
     * `403`s), then apply the mutation against the object graph: the linkage ids
     * resolve to the stored related **objects** and become the parent's reference
     * property — the same resolve-id→object→set seam a whole-resource write uses
     * (see {@see applyRelationship()}). The typed exceptions propagate to the error
     * handler; on success the mutated parent is persisted and its linkage rendered
     * (`200`).
     */
    private function mutateRelationship(
        UpdateRelationshipOperation|AddToRelationshipOperation|RemoveFromRelationshipOperation $operation,
        JsonApiRequestInterface $body,
        Mode $mode,
    ): IdentifierResponse|ErrorResponse {
        $server = $this->server($operation->context());
        $target = $operation->target();
        $type = $target->type;
        $relationshipName = (string) $target->relationship;

        $parent = $this->loadParent($type, $target->id);
        if ($parent === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $relation = $server->resourceFor($type)->relationNamed($relationshipName);
        if ($relation === null) {
            return ErrorResponse::fromException(new RelationshipNotExists($relationshipName));
        }

        // Add / remove are to-many operations: a POST / DELETE to a to-one
        // relationship endpoint is a cardinality error (400).
        if ($mode !== Mode::Replace && $relation->isToMany() === false) {
            throw new RelationshipTypeInappropriate($relationshipName, 'to-one', 'to-many');
        }

        $linkage = $relation->isToMany()
            ? $body->getRelationshipDataToMany($relationshipName)
            : $body->getRelationshipDataToOne($relationshipName);

        $this->guardMutability($relation, $relationshipName, $linkage, $mode);
        $this->applyRelationship($parent, $relation, $linkage, $mode);

        $this->repository->update($type, $parent, (string) $target->id);

        return IdentifierResponse::forRelationship($parent, $server->serializerFor($type), $relationshipName);
    }

    /**
     * Enforces the relation's mutability flags for the requested mutation, throwing
     * core's typed `403`s — the same gate `AbstractResource::hydrateRelationship()`
     * applies, reapplied here because this handler owns the object-graph write
     * itself: a to-one clear (`data: null`) is a removal (`allowsRemove()`), a
     * non-null to-one PATCH is a replacement (`allowsReplace()`); a to-many PATCH is
     * a replacement, a POST an add (`allowsAdd()`), a DELETE a removal.
     *
     * @param ToOneRelationship|ToManyRelationship $linkage
     */
    private function guardMutability(
        RelationInterface $relation,
        string $relationshipName,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
    ): void {
        if ($linkage instanceof ToOneRelationship) {
            if ($linkage->resourceIdentifier === null) {
                if ($relation->allowsRemove() === false) {
                    throw new RemovalProhibited($relationshipName);
                }

                return;
            }

            if ($relation->allowsReplace() === false) {
                throw new FullReplacementProhibited($relationshipName);
            }

            return;
        }

        if ($mode === Mode::Replace && $relation->allowsReplace() === false) {
            throw new FullReplacementProhibited($relationshipName);
        }

        if ($mode === Mode::Add && $relation->allowsAdd() === false) {
            throw new AdditionProhibited($relationshipName);
        }

        if ($mode === Mode::Remove && $relation->allowsRemove() === false) {
            throw new RemovalProhibited($relationshipName);
        }
    }

    /**
     * Applies a parsed linkage to the parent's object-reference property — the
     * single seam both relationship-endpoint mutation and relationships embedded in
     * a whole-resource write share. The store holds the related **objects** (not
     * ids), so the linkage id(s) resolve back to the stored object(s) and become the
     * parent's reference:
     *
     *  - a to-one sets (or, for an empty linkage, clears) the single reference;
     *  - a to-many Replace sets the whole resolved list, Add appends (deduplicated
     *    on id), Remove subtracts the linkage ids from the current list.
     *
     * The property is the relation's column ({@see RelationInterface::column()}, the
     * `storedAs()` override) or, by default, its name — which, in this example, is
     * the very domain property the default reader reads back.
     *
     * @param ToOneRelationship|ToManyRelationship $linkage
     */
    private function applyRelationship(
        object $parent,
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode,
    ): void {
        $property = $relation->column() ?? $relation->name();
        $relatedType = $relation->relatedTypes()[0] ?? '';

        if ($linkage instanceof ToOneRelationship) {
            $parent->{$property} = $linkage->resourceIdentifier?->id !== null
                ? $this->repository->fetchOne($relatedType, $linkage->resourceIdentifier->id)
                : null;

            return;
        }

        $parent->{$property} = $this->applyToMany($parent, $property, $relatedType, $linkage, $mode);
    }

    /**
     * Computes the new to-many object list for `$mode`: Replace sets the whole
     * resolved list, Add appends the resolved members (idempotent on id), Remove
     * subtracts the linkage ids from the current list.
     *
     * @return list<object>
     */
    private function applyToMany(
        object $parent,
        string $property,
        string $relatedType,
        ToManyRelationship $linkage,
        Mode $mode,
    ): array {
        $incomingIds = \array_values(\array_filter(
            $linkage->getResourceIdentifierIds(),
            static fn(?string $id): bool => $id !== null && $id !== '',
        ));

        if ($mode === Mode::Remove) {
            $remove = \array_fill_keys($incomingIds, true);

            return \array_values(\array_filter(
                $this->currentList($parent, $property),
                fn(object $member): bool => !isset($remove[$this->idOf($member)]),
            ));
        }

        $resolved = [];
        foreach ($incomingIds as $id) {
            $related = $this->repository->fetchOne($relatedType, $id);
            if ($related !== null) {
                $resolved[$id] = $related;
            }
        }

        if ($mode === Mode::Replace) {
            return \array_values($resolved);
        }

        // Mode::Add — append the resolved members, deduplicating on id so add is
        // idempotent (an already-present member is not duplicated).
        $next = [];
        foreach ($this->currentList($parent, $property) as $member) {
            $next[$this->idOf($member)] = $member;
        }
        foreach ($resolved as $id => $member) {
            $next[$id] = $member;
        }

        return \array_values($next);
    }

    /**
     * The parent's current to-many member list, normalised to a list of objects.
     *
     * @return list<object>
     */
    private function currentList(object $parent, string $property): array
    {
        /** @var mixed $current */
        $current = $parent->{$property} ?? [];

        return \array_values(\array_filter(
            \is_iterable($current) ? [...$current] : [],
            static fn(mixed $member): bool => \is_object($member),
        ));
    }

    /**
     * The JSON:API id of a stored related object — read off its public `id` member,
     * the shape every seed object uses.
     */
    private function idOf(object $member): string
    {
        /** @var mixed $id */
        $id = $member->id ?? null;

        return \is_scalar($id) ? (string) $id : \spl_object_hash($member);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * A {@see PolymorphicSerializer} for a polymorphic to-many's mixed members:
     * each member resolves its serializer among the relation's declared types,
     * throwing when no declared type serializes a member.
     */
    private function polymorphicSerializer(RelationInterface $relation, Server $server): PolymorphicSerializer
    {
        return new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => $relation->resolveSerializer($object, $server)
                ?? throw new \LogicException(\sprintf(
                    'No declared type of the "%s" relationship serializes a related object.',
                    $relation->name(),
                )),
        );
    }

    /**
     * Loads the parent of `$type` through the repository, or `null` when the id is
     * absent or no such resource exists.
     */
    private function loadParent(string $type, ?string $id): ?object
    {
        if ($id === null) {
            return null;
        }

        return $this->repository->fetchOne($type, $id);
    }

    /**
     * Throws the demonstration {@see PaymentRequired} `402` when creating a private
     * playlist without the `premium` flag — the worked custom-exception referent.
     */
    private function guardPremium(string $type, JsonApiRequestInterface $body): void
    {
        if ($type !== 'playlists') {
            return;
        }

        $public = $body->getResourceAttribute('public');
        $premium = $body->getResourceAttribute('premium');
        if ($public === false && $premium !== true) {
            throw new PaymentRequired('Creating a private playlist requires a premium account.');
        }
    }

    /**
     * A fresh, plain domain object for `$type` — a tiny type → constructor map. The
     * library never dictates instantiation, so the handler owns it; each domain
     * model has a no-arg constructor with defaulted properties.
     */
    private function newInstanceFor(string $type): object
    {
        return match ($type) {
            'artists' => new Artist(),
            'albums' => new Album(),
            'tracks' => new Track(),
            'playlists' => new Playlist(),
            'users' => new User(),
            'favorites' => new Favorite(),
            'libraries' => new Library(),
            default => throw new \LogicException(\sprintf('No domain factory for type "%s".', $type)),
        };
    }

    /**
     * The JSON:API request behind the operation: the originating HTTP request under
     * {@see Server::handle()}, or a minimal bare `GET` request under
     * {@see Server::dispatch()} (where there is no HTTP message) so the repository's
     * criteria + window have a request to read — with no query params, that yields
     * an unfiltered, unsorted, default-windowed read.
     */
    private function request(OperationContext $context): JsonApiRequestInterface
    {
        $request = $context->httpRequest();
        if ($request instanceof JsonApiRequestInterface) {
            return $request;
        }

        return new JsonApiRequest(new \Nyholm\Psr7\ServerRequest('GET', '/'));
    }

    private function server(OperationContext $context): Server
    {
        $server = $context->server;
        \assert($server instanceof Server);

        return $server;
    }
}
