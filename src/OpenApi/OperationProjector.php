<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;

/**
 * Projects a type's HTTP surface into OpenAPI {@see PathItem}s (design §4.4–4.6,
 * D8/D10/D12) — the full path/operation projection:
 *
 * - **CRUD** — resource-level `GET`/`POST` on `/{uriType}` and `GET`/`PATCH`/`DELETE`
 *   on `/{uriType}/{id}`, honouring the per-type operation allow-list
 *   ({@see TypeMetadataInterface::operations()}).
 * - **Relationship & related endpoints** — per relation, gated by its endpoint
 *   exposure ({@see RelationMetadataInterface::exposesRelatedEndpoint()} /
 *   {@see RelationMetadataInterface::exposesRelationshipEndpoint()}) and mutation flags
 *   ({@see RelationMetadataInterface::allowsReplace()} / `allowsAdd` / `allowsRemove`):
 *   a related read on `…/{id}/{rel}` and the `GET`/`PATCH`/`POST`/`DELETE` linkage
 *   endpoints on `…/{id}/relationships/{rel}`.
 * - **Custom actions** — per {@see ActionMetadataInterface}, mounted under the
 *   `-actions` segment (resource or collection scope), with input-mode-driven request
 *   bodies and per-action security (§4.5).
 *
 * Each operation enumerates its concrete query parameters, request body and standard
 * error responses, and carries its tags ({@see TypeMetadataInterface::tags()}, §4.7)
 * plus the configured per-operation security requirement (§4.6 / D8).
 *
 * It is a **pure** projector (no I/O, no Symfony): it composes the Slice-1
 * {@see SchemaProjector} (for `filter[]` value schemas) and the OAS VO model, and
 * `$ref`s the component schemas the {@see OpenApiProjector} already emitted by their
 * stable {@see ComponentNaming} names.
 */
final class OperationProjector
{
    public function __construct(
        private readonly SchemaProjector $schemaProjector = new SchemaProjector(),
    ) {}

    /**
     * Builds every {@see PathItem} for `$type`, keyed by path template: its allowed
     * CRUD endpoints, its relations' exposed related / relationship endpoints, and its
     * custom-action endpoints. A type with no CRUD operation still contributes its
     * relationship and action paths (a standalone serializer with only actions, say);
     * a type with nothing exposed contributes an empty map.
     *
     * @return array<string, PathItem> path template → {@see PathItem}
     */
    public function projectType(TypeMetadataInterface $type, ServerMetadataInterface $server): array
    {
        $operations = $this->allowedOperations($type);

        $paths = [];

        if ($operations !== []) {
            $collection = $this->collectionPathItem($type, $server, $operations);
            if ($collection !== null) {
                $paths['/' . $type->uriType()] = $collection;
            }

            $resource = $this->resourcePathItem($type, $server, $operations);
            if ($resource !== null) {
                $paths['/' . $type->uriType() . '/{id}'] = $resource;
            }
        }

        foreach ($this->relationshipPaths($type, $server) as $path => $item) {
            $paths[$path] = $item;
        }

        foreach ($this->actionPaths($type, $server) as $path => $item) {
            $paths[$path] = $item;
        }

        return $paths;
    }

    // ---- Path items -------------------------------------------------------------

    /**
     * The collection-scoped path item (`/{uriType}`): `GET` (fetch collection) and/or
     * `POST` (create), whichever the allow-list permits.
     *
     * @param array<string, true> $operations the allowed operations as a presence set
     */
    private function collectionPathItem(TypeMetadataInterface $type, ServerMetadataInterface $server, array $operations): ?PathItem
    {
        $item = new PathItem();
        $any = false;

        if (isset($operations[OperationType::FetchCollection->value])) {
            $item = $item->withOperation('get', $this->fetchCollectionOperation($type, $server));
            $any = true;
        }
        if (isset($operations[OperationType::Create->value])) {
            $item = $item->withOperation('post', $this->createOperation($type, $server));
            $any = true;
        }

        return $any ? $item : null;
    }

    /**
     * The resource-scoped path item (`/{uriType}/{id}`): `GET` / `PATCH` / `DELETE`,
     * whichever the allow-list permits. The `{id}` path parameter is shared at the
     * path-item level (it applies to every method).
     *
     * @param array<string, true> $operations the allowed operations as a presence set
     */
    private function resourcePathItem(TypeMetadataInterface $type, ServerMetadataInterface $server, array $operations): ?PathItem
    {
        $methods = [];
        if (isset($operations[OperationType::FetchOne->value])) {
            $methods['get'] = $this->fetchOneOperation($type, $server);
        }
        if (isset($operations[OperationType::Update->value])) {
            $methods['patch'] = $this->updateOperation($type, $server);
        }
        if (isset($operations[OperationType::Delete->value])) {
            $methods['delete'] = $this->deleteOperation($type, $server);
        }

        if ($methods === []) {
            return null;
        }

        $item = new PathItem(parameters: [$this->idPathParameter($type)]);
        foreach ($methods as $method => $operation) {
            $item = $item->withOperation($method, $operation);
        }

        return $item;
    }

    // ---- Operations -------------------------------------------------------------

    private function fetchCollectionOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $parameters = $this->concatParameters(
            $this->filterParameters($type->filters()),
            [$this->sortParameter($type->sorts())],
            [$this->includeParameter($type->includablePaths())],
            $this->fieldsParameters($type, $server, $type->includablePaths()),
            $this->pageParameters($type->pageSchema(), $server),
            [$this->withCountParameter($this->collectionWithCountTokens($type), $server)],
            [$this->relatedQueryParameter($type, $server)],
        );

        $security = $this->securityFor($type, OperationType::FetchCollection, $server);

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::FetchCollection) as $response) {
            $responses = $responses->with((string) $response->status(), $this->fetchCollectionSuccessResponse($type, $response));
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'List ' . $type->type(),
            description: $this->crudOperationDescription($type, OperationType::FetchCollection),
            operationId: 'fetchCollection.' . $type->type(),
            parameters: $parameters,
            security: $security,
        );
    }

    private function fetchOneOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $parameters = $this->concatParameters(
            [$this->includeParameter($type->includablePaths())],
            $this->fieldsParameters($type, $server, $type->includablePaths()),
            [$this->relatedQueryParameter($type, $server)],
        );

        $security = $this->securityFor($type, OperationType::FetchOne, $server);

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::FetchOne) as $response) {
            $responses = $responses->with((string) $response->status(), $this->fetchOneSuccessResponse($type, $response));
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Fetch a ' . $type->type(),
            description: $this->crudOperationDescription($type, OperationType::FetchOne),
            operationId: 'fetchOne.' . $type->type(),
            parameters: $parameters,
            security: $security,
        );
    }

    private function createOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $base = ComponentNaming::base($type->type());

        // A standalone serializer-only type (no field inventory) carries no
        // create-request component; fall back to the permissive write envelope ref.
        $requestSchema = $type->hasFields()
            ? Schema::ref(ComponentNaming::schemaRef($base . 'CreateRequest'))
            : Schema::ref(ComponentNaming::schemaRef($base . 'Resource'));

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::Create) as $response) {
            $responses = $responses->with((string) $response->status(), $this->createSuccessResponse($type, $response));
        }
        $security = $this->securityFor($type, OperationType::Create, $server);
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '409', '415', '422', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Create a ' . $type->type(),
            description: $this->crudOperationDescription($type, OperationType::Create),
            operationId: 'create.' . $type->type(),
            requestBody: RequestBody::ofSchema($requestSchema),
            security: $security,
        );
    }

    private function updateOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $base = ComponentNaming::base($type->type());

        $requestSchema = $type->hasFields()
            ? Schema::ref(ComponentNaming::schemaRef($base . 'UpdateRequest'))
            : Schema::ref(ComponentNaming::schemaRef($base . 'Resource'));

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::Update) as $response) {
            $responses = $responses->with((string) $response->status(), $this->updateSuccessResponse($type, $response));
        }
        $security = $this->securityFor($type, OperationType::Update, $server);
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '409', '415', '422', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Update a ' . $type->type(),
            description: $this->crudOperationDescription($type, OperationType::Update),
            operationId: 'update.' . $type->type(),
            requestBody: RequestBody::ofSchema($requestSchema),
            security: $security,
        );
    }

    private function deleteOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $security = $this->securityFor($type, OperationType::Delete, $server);

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::Delete) as $response) {
            $responses = $responses->with((string) $response->status(), $this->deleteSuccessResponse($type, $response));
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Delete a ' . $type->type(),
            description: $this->crudOperationDescription($type, OperationType::Delete),
            operationId: 'delete.' . $type->type(),
            security: $security,
        );
    }

    // ---- Per-operation success responses ----------------------------------------

    /**
     * The concrete {@see Response} for one declared create success response. `201` is
     * the created-resource shape (with `Location`), `204` a bodyless create, `202` the
     * async-accept shape.
     */
    private function createSuccessResponse(TypeMetadataInterface $type, OperationResponseInterface $response): Response
    {
        return match ($response->status()) {
            201 => new Response(
                'The created ' . $type->type() . ' resource.',
                headers: ['Location' => new Header(
                    'The URL of the created resource.',
                    schema: Schema::ofType('string')->withFormat('uri-reference'),
                )],
                content: [MediaType::JSON_API => MediaType::ofSchema(
                    Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Document')),
                )],
            ),
            204 => Response::noContent('The resource was created; no content is returned.'),
            202 => $this->acceptedResponse($this->jobTypeOf($response)),
            default => throw $this->unexpectedResponse(OperationType::Create, $response->status()),
        };
    }

    /**
     * The concrete {@see Response} for one declared update success response. `200` is
     * the updated-resource shape, `204` a bodyless update, `202` the async-accept shape.
     */
    private function updateSuccessResponse(TypeMetadataInterface $type, OperationResponseInterface $response): Response
    {
        return match ($response->status()) {
            200 => Response::ofSchema(
                'The updated ' . $type->type() . ' resource.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Document')),
            ),
            204 => Response::noContent('The resource was updated; no content is returned.'),
            202 => $this->acceptedResponse($this->jobTypeOf($response)),
            default => throw $this->unexpectedResponse(OperationType::Update, $response->status()),
        };
    }

    /**
     * The concrete {@see Response} for one declared delete success response. `204` is
     * the bodyless delete, `200` a meta-only document.
     */
    private function deleteSuccessResponse(TypeMetadataInterface $type, OperationResponseInterface $response): Response
    {
        return match ($response->status()) {
            204 => Response::noContent('The resource was deleted.'),
            200 => Response::ofSchema(
                'The resource was deleted; a meta-only document is returned.',
                Schema::ref(ComponentNaming::schemaRef('MetaDocument')),
            ),
            default => throw $this->unexpectedResponse(OperationType::Delete, $response->status()),
        };
    }

    /**
     * The concrete {@see Response} for one declared fetch-one success response. `200`
     * is the resource document, `303` the async-completion redirect (headers only).
     */
    private function fetchOneSuccessResponse(TypeMetadataInterface $type, OperationResponseInterface $response): Response
    {
        return match ($response->status()) {
            200 => Response::ofSchema(
                'The requested ' . $type->type() . ' resource.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Document')),
            ),
            303 => $this->seeOtherResponse(),
            default => throw $this->unexpectedResponse(OperationType::FetchOne, $response->status()),
        };
    }

    /**
     * The concrete {@see Response} for one declared fetch-collection success response
     * (`200`, the collection document).
     */
    private function fetchCollectionSuccessResponse(TypeMetadataInterface $type, OperationResponseInterface $response): Response
    {
        return match ($response->status()) {
            200 => Response::ofSchema(
                'A collection of ' . $type->type() . ' resources.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Collection')),
            ),
            default => throw $this->unexpectedResponse(OperationType::FetchCollection, $response->status()),
        };
    }

    /**
     * The `202 Accepted` async-accept response: the pollable `$jobType` job document,
     * with `Content-Location` (the poll URL) and `Retry-After` (the poll hint) headers.
     */
    private function acceptedResponse(string $jobType): Response
    {
        return new Response(
            'The request was accepted for asynchronous processing.',
            headers: [
                'Content-Location' => new Header(
                    'The URL of the job resource to poll for completion.',
                    schema: Schema::ofType('string')->withFormat('uri-reference'),
                ),
                'Retry-After' => new Header(
                    'The number of seconds to wait before polling the job resource.',
                    schema: Schema::ofType('integer'),
                ),
            ],
            content: [MediaType::JSON_API => MediaType::ofSchema(
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($jobType) . 'Document')),
            )],
        );
    }

    /**
     * The `303 See Other` async-completion response: a `Location` header pointing at
     * the produced resource, no body.
     */
    private function seeOtherResponse(): Response
    {
        return new Response(
            'The asynchronous operation is complete; follow Location to the produced resource.',
            headers: ['Location' => new Header(
                'The URL of the produced resource.',
                schema: Schema::ofType('string')->withFormat('uri-reference'),
            )],
        );
    }

    /**
     * The job type of a `202` response, guaranteed non-null by
     * {@see \haddowg\JsonApi\OpenApi\Metadata\OperationResponses::validate()}; the
     * guard is defensive against a hand-built metadata source.
     */
    private function jobTypeOf(OperationResponseInterface $response): string
    {
        $jobType = $response->jobType();
        if ($jobType === null) {
            throw new \LogicException('A 202 Accepted response was declared without a job type.');
        }

        return $jobType;
    }

    /**
     * A guard for a status a validated response set should never contain for the
     * given operation (defensive against a hand-built metadata source).
     */
    private function unexpectedResponse(OperationType $operation, int $status): \LogicException
    {
        return new \LogicException(\sprintf(
            'Status %d is not a valid success response for the %s operation.',
            $status,
            $operation->value,
        ));
    }

    /**
     * The description for one CRUD operation: the type's declared override (
     * {@see TypeMetadataInterface::operationDescription()}) when present, else a
     * generated default describing the operation in terms of the type — fuller than
     * the terse `summary`.
     */
    private function crudOperationDescription(TypeMetadataInterface $type, OperationType $operation): string
    {
        $declared = $type->operationDescription($operation);
        if ($declared !== null) {
            return $declared;
        }

        $name = $type->type();

        return match ($operation) {
            OperationType::FetchCollection => 'Returns a paginated collection of `' . $name . '` resources.',
            OperationType::FetchOne => 'Returns a single `' . $name . '` resource by its `id`.',
            OperationType::Create => 'Creates a new `' . $name . '` resource from the supplied attributes and relationships.',
            OperationType::Update => 'Updates an existing `' . $name . '` resource, applying the supplied attributes and relationships.',
            OperationType::Delete => 'Deletes the `' . $name . '` resource identified by its `id`.',
        };
    }

    // ---- Relationship & related endpoints (stage B) -----------------------------

    /**
     * Every relation's exposed related ({@see RelationMetadataInterface::exposesRelatedEndpoint()})
     * and relationship ({@see RelationMetadataInterface::exposesRelationshipEndpoint()})
     * endpoints. The `{relationship}`/`{rel}` segment is **literal** in the projected
     * document — one path per concrete relation name (`…/{id}/author`, not a parametric
     * segment). The `{id}` path parameter is shared at the path-item level.
     *
     * @return array<string, PathItem>
     */
    private function relationshipPaths(TypeMetadataInterface $type, ServerMetadataInterface $server): array
    {
        $paths = [];
        $idParameter = $this->idPathParameter($type);

        foreach ($type->relations() as $relation) {
            if ($relation->exposesRelatedEndpoint()) {
                $paths['/' . $type->uriType() . '/{id}/' . $relation->name()] = (new PathItem(parameters: [$idParameter]))
                    ->withOperation('get', $this->relatedOperation($type, $relation, $server));
            }

            if ($relation->exposesRelationshipEndpoint()) {
                $item = new PathItem(parameters: [$idParameter]);
                foreach ($this->relationshipOperations($type, $relation, $server) as $method => $operation) {
                    $item = $item->withOperation($method, $operation);
                }
                $paths['/' . $type->uriType() . '/{id}/relationships/' . $relation->name()] = $item;
            }
        }

        return $paths;
    }

    /**
     * The related-resource read operation (`GET /{uriType}/{id}/{rel}`) → a **related
     * document** ($ref the related type's collection for a to-many, the per-relation
     * nullable related document for a to-one). A to-many related collection reuses the
     * CRUD query parameters scoped to the relation's own filters/sorts/pagination/
     * includes (§4.4).
     */
    private function relatedOperation(TypeMetadataInterface $type, RelationMetadataInterface $relation, ServerMetadataInterface $server): Operation
    {
        $base = ComponentNaming::base($type->type());
        $relBase = $base . ComponentNaming::base($relation->name());

        // A related endpoint returns the **related** resource(s) as primary data, so its
        // `?include` and `fields[]` are scoped to the related type(s) — not the parent.
        $includeParameter = $this->includeParameter($relation->relatedIncludablePaths());
        $fieldsParameters = $this->relatedFieldsParameters($relation, $server);

        if ($relation->isToMany()) {
            $responseRef = $this->relatedCollectionResponseRef($relation, $relBase);
            $parameters = $this->concatParameters(
                $this->filterParameters($this->relatedFilterVocabulary($relation, $server)),
                [$this->sortParameter($this->relatedSortVocabulary($relation, $server))],
                [$includeParameter],
                $fieldsParameters,
                $this->pageParameters($relation->pageSchema(), $server),
                [$this->withCountParameter($this->relatedWithCountTokens($relation, $server), $server)],
            );
            $successDescription = 'The related ' . $relation->name() . ' collection.';
        } else {
            $responseRef = Schema::ref(ComponentNaming::schemaRef($relBase . 'RelatedDocument'));
            // A MONOMORPHIC to-one related endpoint honours the related resource's
            // `filter[]` vocabulary (a relation filter that excludes the target nulls the
            // linkage), but not `sort`/`page` (a to-one is not a collection; those are
            // simply not advertised). A POLYMORPHIC to-one (MorphTo) has no shared filter
            // vocabulary — any filter `400`s — so it advertises none.
            $parameters = $this->concatParameters(
                \count($relation->relatedTypes()) === 1
                    ? $this->filterParameters($this->relatedFilterVocabulary($relation, $server))
                    : [],
                [$includeParameter],
                $fieldsParameters,
            );
            $successDescription = 'The related ' . $relation->name() . ' resource (or `null`).';
        }

        // A related read mirrors a fetch, but the relation's own declared read security
        // OVERRIDES the parent's (a relation may be more *or* less permissive than the
        // type it hangs off).
        $security = $this->relationSecurityFor($relation->securityRead(), $type, OperationType::FetchOne, $server);

        $responses = (new Responses())
            ->with('200', Response::ofSchema($successDescription, $responseRef));
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Fetch the related ' . $relation->name() . ' of a ' . $type->type(),
            description: $this->relationDescription(
                $relation,
                $relation->isToMany()
                    ? 'Returns the related `' . $relation->name() . '` resources of a `' . $type->type() . '`.'
                    : 'Returns the related `' . $relation->name() . '` resource of a `' . $type->type() . '` (or `null`).',
            ),
            operationId: 'fetchRelated.' . $type->type() . '.' . $relation->name(),
            parameters: $parameters,
            // A related read mirrors a fetch — it carries security iff fetch-one does.
            security: $security,
        );
    }

    /**
     * The relationship-linkage operations on `…/relationships/{rel}`: `GET` (read
     * linkage — always when the endpoint is exposed), plus the mutating verbs gated by
     * the relation's mutation flags: `PATCH` (replace, when {@see allowsReplace()}),
     * and — to-many only — `POST` (add, {@see allowsAdd()}) and `DELETE` (remove,
     * {@see allowsRemove()}).
     *
     * @return array<string, Operation> lower-cased HTTP method → operation
     */
    private function relationshipOperations(TypeMetadataInterface $type, RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $base = ComponentNaming::base($type->type());
        $relBase = $base . ComponentNaming::base($relation->name());
        $documentRef = Schema::ref(ComponentNaming::schemaRef($relBase . 'RelationshipDocument'));
        $tags = $type->tags();

        $operations = [];

        // GET — read the relationship linkage (mirrors a fetch). A **monomorphic to-one**
        // relationship endpoint honours the related resource's `filter[]` vocabulary
        // (a relation filter that excludes the target nulls the linkage). A
        // **monomorphic to-many** relationship endpoint is a real queryable, paginated
        // linkage collection at parity with the related endpoint: `filter[]`/`sort`/
        // `page` (+`withCount` when countable) scope against the same merged vocabulary,
        // the page-1 linkage rendering with the relationship object's pagination links
        // (ADR 0096). A **polymorphic** relationship endpoint (to-one or to-many — members
        // span types, no single related provider or shared vocabulary) takes no query
        // parameters: the host rejects a requested `filter`/`sort`/`page` there with a `400`.
        if (!$relation->isToMany()) {
            $getParameters = \count($relation->relatedTypes()) === 1
                ? $this->filterParameters($this->relatedFilterVocabulary($relation, $server))
                : [];
        } elseif (\count($relation->relatedTypes()) === 1) {
            $getParameters = $this->concatParameters(
                $this->filterParameters($this->relatedFilterVocabulary($relation, $server)),
                [$this->sortParameter($this->relatedSortVocabulary($relation, $server))],
                $this->pageParameters($relation->pageSchema(), $server),
                [$this->withCountParameter($this->relationshipWithCountTokens($relation), $server)],
            );
        } else {
            $getParameters = [];
        }
        // The relation's declared read security overrides the parent's fetch gate.
        $getSecurity = $this->relationSecurityFor($relation->securityRead(), $type, OperationType::FetchOne, $server);
        $getResponses = (new Responses())
            ->with('200', Response::ofSchema('The ' . $relation->name() . ' relationship linkage.', $documentRef));
        $operations['get'] = new Operation(
            responses: $this->withErrorResponses($getResponses, $this->authStatuses(['400', '403', '404', '406', '500'], $getSecurity, $server->defaultSecurity())),
            tags: $tags,
            summary: 'Fetch the ' . $relation->name() . ' relationship of a ' . $type->type(),
            description: $this->relationDescription(
                $relation,
                'Returns the `' . $relation->name() . '` relationship linkage of a `' . $type->type() . '`.',
            ),
            operationId: 'fetchRelationship.' . $type->type() . '.' . $relation->name(),
            parameters: $getParameters,
            security: $getSecurity,
        );

        // PATCH — full replacement of the relationship.
        if ($relation->allowsReplace()) {
            $operations['patch'] = $this->relationshipMutationOperation(
                $type,
                $relation,
                $server,
                'Replace the ' . $relation->name() . ' relationship of a ' . $type->type(),
                'Fully replaces the `' . $relation->name() . '` relationship of a `' . $type->type() . '` with the supplied linkage.',
                'updateRelationship',
                $documentRef,
            );
        }

        // POST / DELETE — to-many add / remove only (a to-one has no add/remove verbs).
        if ($relation->isToMany()) {
            if ($relation->allowsAdd()) {
                $operations['post'] = $this->relationshipMutationOperation(
                    $type,
                    $relation,
                    $server,
                    'Add to the ' . $relation->name() . ' relationship of a ' . $type->type(),
                    'Adds the supplied members to the `' . $relation->name() . '` relationship of a `' . $type->type() . '`.',
                    'addRelationship',
                    $documentRef,
                );
            }
            if ($relation->allowsRemove()) {
                $operations['delete'] = $this->relationshipMutationOperation(
                    $type,
                    $relation,
                    $server,
                    'Remove from the ' . $relation->name() . ' relationship of a ' . $type->type(),
                    'Removes the supplied members from the `' . $relation->name() . '` relationship of a `' . $type->type() . '`.',
                    'removeRelationship',
                    $documentRef,
                );
            }
        }

        return $operations;
    }

    /**
     * One relationship-mutation operation (`PATCH`/`POST`/`DELETE` on
     * `…/relationships/{rel}`): a relationship-document request body and a
     * `200` (echoing the linkage) plus the enumerated error responses.
     */
    private function relationshipMutationOperation(
        TypeMetadataInterface $type,
        RelationMetadataInterface $relation,
        ServerMetadataInterface $server,
        string $summary,
        string $defaultDescription,
        string $operationPrefix,
        Schema $documentRef,
    ): Operation {
        // A relationship mutation mirrors an update, but the relation's own declared
        // mutation security OVERRIDES the parent's update gate.
        $security = $this->relationSecurityFor($relation->securityMutate(), $type, OperationType::Update, $server);

        // The handler always echoes the linkage (`200`); it never returns `204` for these
        // arms, so the document advertises only `200` (the spec permits a `204`, but this
        // implementation does not produce one).
        $responses = (new Responses())
            ->with('200', Response::ofSchema('The updated ' . $relation->name() . ' relationship linkage.', $documentRef));
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '409', '415', '422', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: $summary,
            description: $this->relationDescription($relation, $defaultDescription),
            operationId: $operationPrefix . '.' . $type->type() . '.' . $relation->name(),
            requestBody: RequestBody::ofSchema($documentRef),
            security: $security,
        );
    }

    /**
     * The description for a relation's related/relationship operation: the relation's
     * own declared description ({@see RelationMetadataInterface::description()}) when
     * present — it applies to every endpoint of that relationship — else the
     * operation-specific generated default.
     */
    private function relationDescription(RelationMetadataInterface $relation, string $default): string
    {
        return $relation->description() ?? $default;
    }

    /**
     * The `200` response `$ref` for a to-many related-collection endpoint
     * (`GET /{uriType}/{id}/{rel}`).
     *
     * A **monomorphic** relation (one related type) reuses that type's
     * `<RelatedType>Collection` envelope; a relation declaring no related types
     * degrades to a collection keyed by the relation's own name (matching the
     * synthetic unregistered-related emission). A **polymorphic** relation (more than
     * one related type) cannot reuse a single member's collection — its members span
     * types — so it `$ref`s a **per-relation** `<Base><Rel>RelatedCollection` document
     * whose `data.items` is the `anyOf` of every member resource (emitted by the
     * {@see OpenApiProjector}, mirroring the to-one polymorphic related document).
     */
    private function relatedCollectionResponseRef(RelationMetadataInterface $relation, string $relBase): Schema
    {
        if (\count($relation->relatedTypes()) > 1) {
            return Schema::ref(ComponentNaming::schemaRef($relBase . 'RelatedCollection'));
        }

        $relatedBase = $this->relatedComponentBase($relation);

        return Schema::ref(ComponentNaming::schemaRef($relatedBase . 'Collection'));
    }

    /**
     * The component-name base for a relation's single related resource component. A
     * monomorphic relation names the single related type; a relation with no declared
     * types degrades to the relation's own name (matching the synthetic
     * unregistered-related emission). Polymorphic relations are handled separately by
     * {@see relatedCollectionResponseRef()} / the per-member resolution, never here.
     */
    private function relatedComponentBase(RelationMetadataInterface $relation): string
    {
        $types = $relation->relatedTypes();

        return $types === [] ? ComponentNaming::base($relation->name()) : ComponentNaming::base($types[0]);
    }

    // ---- Custom-action endpoints (stage B) --------------------------------------

    /**
     * Every custom-action {@see PathItem} for the type (§4.5): one path per action
     * under the `-actions` segment — `/{uriType}/{id}/-actions/{path}` for a
     * resource-scoped action, `/{uriType}/-actions/{path}` for a collection-scoped one
     * — carrying the action's declared method(s). A resource-scoped action shares the
     * `{id}` path parameter at the path-item level.
     *
     * @return array<string, PathItem>
     */
    private function actionPaths(TypeMetadataInterface $type, ServerMetadataInterface $server): array
    {
        $paths = [];

        foreach ($type->actions() as $action) {
            $resourceScoped = $action->scope() === ActionScope::Resource;
            $path = $resourceScoped
                ? '/' . $type->uriType() . '/{id}/-actions/' . $action->path()
                : '/' . $type->uriType() . '/-actions/' . $action->path();

            $item = $resourceScoped
                ? new PathItem(parameters: [$this->idPathParameter($type)])
                : new PathItem();

            $operation = $this->actionOperation($type, $action, $server);
            foreach ($action->methods() as $httpMethod) {
                $item = $item->withOperation(\strtolower($httpMethod), $operation);
            }

            $paths[$path] = $item;
        }

        return $paths;
    }

    /**
     * One custom action's operation (§4.5): its input mode → `requestBody`
     * (`None` → none; `Document` → the input type's create-request schema; `Raw` → a
     * permissive binary body under a generic media type with relaxed content-type
     * negotiation), its declared responses ({@see ActionMetadataInterface::responds()})
     * → one success response each, its tags, and the configured security requirement
     * when {@see isSecured()}.
     */
    private function actionOperation(TypeMetadataInterface $type, ActionMetadataInterface $action, ServerMetadataInterface $server): Operation
    {
        $responses = new Responses();
        foreach ($action->responds() as $response) {
            $responses = $responses->with((string) $response->status(), $this->actionSuccessResponse($response));
        }

        $security = $action->isSecured() ? $this->configuredSecurity($server) : null;

        // Every action negotiates the `Accept` header (`406`); a `None`/`Document` input
        // mode also negotiates the `Content-Type` (`415`), while a `Raw` mode relaxes it.
        $statuses = ['400', '403', '404', '406', '422', '500'];
        if ($action->inputMode() !== ActionInputMode::Raw) {
            $statuses[] = '415';
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses($statuses, $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $action->tags(),
            // A custom action carries the author's summary/description when declared, else a
            // generated default — so an action (the least path-inferable operation) is never the
            // only operation left undescribed (D19).
            summary: $action->summary() ?? ('Invoke the `' . $action->path() . '` action'),
            description: $action->description() ?? ('Invokes the `' . $action->path() . '` custom action on a `' . $type->type() . '` resource.'),
            operationId: 'action.' . $type->type() . '.' . $action->path(),
            requestBody: $this->actionRequestBody($action),
            security: $security,
        );
    }

    /**
     * The action's request body for its input mode (§4.5): `None` → no body;
     * `Document` → the input type's create-request schema under the JSON:API media
     * type; `Raw` → a permissive string/binary body under `application/octet-stream`
     * (the author owns the negotiation, so the schema is left open).
     */
    private function actionRequestBody(ActionMetadataInterface $action): ?RequestBody
    {
        return match ($action->inputMode()) {
            ActionInputMode::None => null,
            ActionInputMode::Document => $this->actionDocumentRequestBody($action),
            ActionInputMode::Raw => new RequestBody(
                content: ['application/octet-stream' => MediaType::ofSchema(Schema::ofType('string')->withFormat('binary'))],
                description: 'A raw request body; the action relaxes content-type negotiation and owns the body shape.',
                required: false,
            ),
        };
    }

    /**
     * The `Document`-mode request body: the input type's create-request schema (or the
     * permissive resource ref for a type with no field inventory) under the JSON:API
     * media type. An action declaring `Document` input with no `inputType` degrades to
     * a permissive JSON:API document body.
     */
    private function actionDocumentRequestBody(ActionMetadataInterface $action): RequestBody
    {
        $inputType = $action->inputType();
        if ($inputType === null) {
            return RequestBody::ofSchema(Schema::ofType('object'));
        }

        return RequestBody::ofSchema(
            Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($inputType) . 'CreateRequest')),
        );
    }

    /**
     * The concrete success {@see Response} for one declared action response:
     * {@see ActionResource} → `200` with that type's document; {@see MetaResult} → `200`
     * meta-only document; {@see NoContent} → `204`; {@see Accepted} → the async `202`;
     * {@see SeeOther} → the `303` completion redirect.
     */
    private function actionSuccessResponse(ActionResponse $response): Response
    {
        return match (true) {
            $response instanceof ActionResource => Response::ofSchema(
                'The action result.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($response->bodyType()) . 'Document')),
            ),
            $response instanceof MetaResult => Response::ofSchema(
                'The action result: a meta-only document.',
                Schema::ref(ComponentNaming::schemaRef('MetaDocument')),
            ),
            $response instanceof NoContent => Response::noContent('The action completed with no content.'),
            $response instanceof Accepted => $this->acceptedResponse($response->jobType()),
            $response instanceof SeeOther => $this->seeOtherResponse(),
            default => throw new \LogicException(\sprintf(
                'Unsupported action response %s (status %d).',
                $response::class,
                $response->status(),
            )),
        };
    }

    // ---- Parameters (reused by the stage-B relationship/action projection) ------

    /**
     * One `filter[<key>]` query parameter per declared filter; its value schema is
     * projected from the filter's value constraints (§4.4). A presence-only filter
     * (no constraints) yields a permissive value schema.
     *
     * A filter with a **structured** wire shape describes its own parameter envelope
     * via {@see \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter}: a
     * {@see \haddowg\JsonApi\Resource\Filter\Range} (and its
     * {@see \haddowg\JsonApi\Resource\Filter\DateRange} specialisation) wraps its
     * per-bound value schema into an **object** with `min`/`max` properties and the
     * OAS `deepObject` style for the nested `filter[<key>][min]`/`[max]` wire shape
     * (ADR 0076/0077). A scalar filter (the default) is its constraint-derived value
     * schema with no style — so a consumer-defined structured filter documents
     * correctly with no change here.
     *
     * A server-composed group ({@see \haddowg\JsonApi\Resource\Filter\WhereAll} /
     * {@see \haddowg\JsonApi\Resource\Filter\WhereAny}) projects as a single scalar
     * `filter[<key>]` too: a fanning group carries the shared value schema from its
     * own `constraints()`, and an all-fixed group (like a `->fixed()` scalar filter)
     * declares none, so it projects as a permissive presence parameter whose
     * description notes the value is server-set — see {@see filterDescription()}.
     *
     * @param list<\haddowg\JsonApi\Resource\Filter\FilterInterface> $filters
     * @return list<Parameter>
     */
    private function filterParameters(array $filters): array
    {
        $parameters = [];
        foreach ($filters as $filter) {
            $valueSchema = $this->schemaProjector->projectConstraints($filter->constraints());
            $shape = $filter instanceof \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter
                ? $filter->describeQueryParameter($valueSchema)
                : new QueryParameterShape($valueSchema);

            $parameters[] = Parameter::query(
                'filter[' . $filter->key() . ']',
                $shape->schema,
                $this->filterDescription($filter),
                style: $shape->style,
                explode: $shape->explode,
            );
        }

        return $parameters;
    }

    /**
     * The description for one filter's `filter[<key>]` parameter: the author's own
     * declared description ({@see \haddowg\JsonApi\Resource\Filter\DescribedFilter},
     * which the convenience filters preset — "Matches values containing the given
     * substring.", "Matches values within the given inclusive numeric range…") when
     * present, else a generic per-key fallback. Read through the `DescribedFilter`
     * interface so it stays type-safe over the bare {@see \haddowg\JsonApi\Resource\Filter\FilterInterface}.
     */
    private function filterDescription(\haddowg\JsonApi\Resource\Filter\FilterInterface $filter): string
    {
        $description = 'Filter the collection by `' . $filter->key() . '`.';
        if ($filter instanceof \haddowg\JsonApi\Resource\Filter\DescribedFilter) {
            $declared = $filter->getDescription();
            if ($declared !== null && $declared !== '') {
                $description = $declared;
            }
        }

        // A presence-triggered filter (a `->fixed()` Where, or a group whose
        // children are all fixed) has a **server-set** value: the parameter's
        // presence applies a canned condition and the request value is ignored, so
        // document it honestly rather than implying a client value input.
        if ($filter instanceof \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter && $filter->isPresenceTriggered()) {
            $description .= ' The value is server-set: include this parameter with any value to apply the filter; the value you send is ignored.';
        }

        return $description;
    }

    /**
     * The single `sort` parameter: a comma-separated **list** of sort fields, each
     * a sortable key or its `-`-prefixed descending form (§4.4). JSON:API carries
     * the list in one parameter, so this is an OAS `form`/`explode: false` array
     * parameter (the standard comma-delimited shape); the allowed tokens are
     * preserved as the array `items` enum. Returns `null` when the type declares no
     * sorts.
     *
     * @param list<\haddowg\JsonApi\Resource\Sort\SortInterface> $sorts
     */
    private function sortParameter(array $sorts): ?Parameter
    {
        if ($sorts === []) {
            return null;
        }

        $tokens = [];
        foreach ($sorts as $sort) {
            $tokens[] = $sort->key();
            $tokens[] = '-' . $sort->key();
        }

        $schema = Schema::ofType('array')
            ->withItems(Schema::ofType('string')->withEnum($tokens));

        return Parameter::query(
            'sort',
            $schema,
            'A comma-separated list of sort fields. Prefix a field with `-` for descending order. Allowed tokens: `'
            . \implode('`, `', $tokens) . '`.',
            style: ParameterStyle::Form,
            explode: false,
        );
    }

    /**
     * The single `include` parameter: a comma-separated **list** of the type's allowed
     * includable relationship paths (§4.4). JSON:API carries the list in one parameter,
     * so this is an OAS `form`/`explode: false` array parameter (the standard
     * comma-delimited shape); the allowed paths are preserved as the array `items`
     * enum. Returns `null` when nothing is includable.
     *
     * @param list<string> $includablePaths
     */
    private function includeParameter(array $includablePaths): ?Parameter
    {
        if ($includablePaths === []) {
            return null;
        }

        $schema = Schema::ofType('array')
            ->withItems(Schema::ofType('string')->withEnum($includablePaths));

        return Parameter::query(
            'include',
            $schema,
            'A comma-separated list of relationship paths to include in a compound document. Allowed paths: `'
            . \implode('`, `', $includablePaths) . '`.',
            style: ParameterStyle::Form,
            explode: false,
        );
    }

    /**
     * The `fields[<type>]` sparse-fieldset parameters (§4.4 / D10): one per type
     * **reachable in the document** — the primary `$type` plus every type a declared
     * `?include` path can resolve to (so a client doing `?include=author&fields[people]=…`
     * uses a parameter the document actually declares). Only field-bearing types
     * contribute a parameter (a standalone serializer with no inventory has no fields to
     * select). Resolution walks the `$server`'s relation graph along each includable
     * path to its terminal type(s).
     *
     * @param list<string> $includablePaths
     * @return list<Parameter>
     */
    private function fieldsParameters(TypeMetadataInterface $type, ServerMetadataInterface $server, array $includablePaths): array
    {
        $byType = [];
        foreach ($server->types() as $candidate) {
            $byType[$candidate->type()] = $candidate;
        }

        $parameters = [];
        foreach ($this->reachableFieldTypes($type, $server, $includablePaths) as $reachableType) {
            $reachable = $byType[$reachableType] ?? null;
            if ($reachable === null) {
                continue;
            }
            $parameters[] = $this->fieldsetParameter($reachable);
        }

        return $parameters;
    }

    /**
     * The `fields[<type>]` parameters for a **related** endpoint
     * (`GET /{type}/{id}/{rel}`), whose primary data is the related type(s): one per
     * related (member) type plus every type reachable through the relation's
     * related-scoped includable paths. A monomorphic relation roots at its single
     * related type; a polymorphic one unions over every member (its
     * `relatedIncludablePaths()` is empty, so only the member types contribute). Only
     * types registered with a field inventory yield a parameter.
     *
     * @return list<Parameter>
     */
    private function relatedFieldsParameters(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $byType = [];
        foreach ($server->types() as $candidate) {
            $byType[$candidate->type()] = $candidate;
        }

        $parameters = [];
        $seen = [];
        foreach ($relation->relatedTypes() as $relatedType) {
            $rootType = $byType[$relatedType] ?? null;
            if ($rootType === null) {
                continue;
            }
            foreach ($this->reachableFieldTypes($rootType, $server, $relation->relatedIncludablePaths()) as $reachableType) {
                if (isset($seen[$reachableType])) {
                    continue;
                }
                $seen[$reachableType] = true;
                $reachable = $byType[$reachableType] ?? null;
                if ($reachable === null) {
                    continue;
                }
                $parameters[] = $this->fieldsetParameter($reachable);
            }
        }

        return $parameters;
    }

    /**
     * A single `fields[<type>]` sparse-fieldset parameter. JSON:API carries the selected
     * members as one comma-separated value, so — mirroring `sort`/`include` — this is an
     * OAS `form`/`explode: false` array parameter whose `items` enumerate the type's
     * selectable member vocabulary: its read-representation attribute names
     * ({@see SchemaProjector::readAttributeNames()}) plus its relation names. The runtime
     * `400`s (`FieldsetMemberUnrecognized`, strict query params default on) an unknown
     * member, so the enum lets a client — and a code generator — offer exactly the members
     * the server accepts. A type with no selectable members (defensive: a field-bearing
     * type normally has some) falls back to a bare string array with no enum.
     */
    private function fieldsetParameter(TypeMetadataInterface $reachable): Parameter
    {
        $members = $this->schemaProjector->readAttributeNames($reachable->fields());
        foreach ($reachable->relations() as $relation) {
            $members[] = $relation->name();
        }

        $items = Schema::ofType('string');
        if ($members !== []) {
            $items = $items->withEnum($members);
        }

        return Parameter::query(
            'fields[' . $reachable->type() . ']',
            Schema::ofType('array')->withItems($items),
            'A comma-separated list of `' . $reachable->type() . '` fields to return (sparse fieldsets).'
            . ($members === [] ? '' : ' Allowed members: `' . \implode('`, `', $members) . '`.'),
            style: ParameterStyle::Form,
            explode: false,
        );
    }

    /**
     * The related metadata for a **monomorphic** relation's single related type, or
     * `null` (a polymorphic relation has no single related type, so no shared
     * filter/sort vocabulary).
     */
    private function relatedTypeMetadata(RelationMetadataInterface $relation, ServerMetadataInterface $server): ?TypeMetadataInterface
    {
        $types = $relation->relatedTypes();
        if (\count($types) !== 1) {
            return null;
        }
        foreach ($server->types() as $candidate) {
            if ($candidate->type() === $types[0]) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The filter vocabulary a related / relationship endpoint honours: the **related
     * resource's own** filters merged with the relation's relation-scoped
     * ({@see RelationMetadataInterface::filters()}) ones — the relation wins on a key
     * collision, mirroring the runtime's merged criteria. A polymorphic relation has
     * no shared vocabulary, so only its own (typically empty) filters apply.
     *
     * @return list<\haddowg\JsonApi\Resource\Filter\FilterInterface>
     */
    private function relatedFilterVocabulary(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $byKey = [];
        foreach ($this->relatedTypeMetadata($relation, $server)?->filters() ?? [] as $filter) {
            $byKey[$filter->key()] = $filter;
        }
        foreach ($relation->filters() as $filter) {
            $byKey[$filter->key()] = $filter;
        }

        return \array_values($byKey);
    }

    /**
     * The sort vocabulary a related to-many endpoint honours (the related resource's
     * own sorts merged with the relation's), mirroring {@see relatedFilterVocabulary()}.
     *
     * @return list<\haddowg\JsonApi\Resource\Sort\SortInterface>
     */
    private function relatedSortVocabulary(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $byKey = [];
        foreach ($this->relatedTypeMetadata($relation, $server)?->sorts() ?? [] as $sort) {
            $byKey[$sort->key()] = $sort;
        }
        foreach ($relation->sorts() as $sort) {
            $byKey[$sort->key()] = $sort;
        }

        return \array_values($byKey);
    }

    /**
     * The distinct, **field-bearing** JSON:API types reachable in a document whose
     * primary data is `$type` under the given `?include` `$includablePaths`: the
     * primary type plus the terminal type(s) every includable path resolves to via the
     * `$server`'s relation graph. The owning type leads (stable, idiomatic order); the
     * rest follow in first-discovery order, deduped. A path segment that cannot be
     * resolved (an unknown relation, or a related type absent from the server) simply
     * contributes nothing — never a wrong parameter.
     *
     * @param list<string> $includablePaths
     * @return list<string>
     */
    private function reachableFieldTypes(TypeMetadataInterface $type, ServerMetadataInterface $server, array $includablePaths): array
    {
        $byType = [];
        foreach ($server->types() as $candidate) {
            $byType[$candidate->type()] = $candidate;
        }

        $reachable = [];
        $add = static function (string $candidate) use (&$reachable, $byType): void {
            if (isset($reachable[$candidate]) || !isset($byType[$candidate]) || !$byType[$candidate]->hasFields()) {
                return;
            }
            $reachable[$candidate] = true;
        };

        $add($type->type());

        foreach ($includablePaths as $path) {
            foreach ($this->terminalTypesOfPath($type, $path, $byType) as $terminalType) {
                $add($terminalType);
            }
        }

        return \array_keys($reachable);
    }

    /**
     * The related type(s) a dotted `?include` path (e.g. `author.company`) resolves to,
     * by walking the relation graph segment by segment from `$origin`. A polymorphic
     * segment branches into every member type; an unresolvable segment prunes that
     * branch. Returns the terminal types only (the deepest segment's related types).
     *
     * @param array<string, TypeMetadataInterface> $byType
     * @return list<string>
     */
    private function terminalTypesOfPath(TypeMetadataInterface $origin, string $path, array $byType): array
    {
        $current = [$origin->type()];
        foreach (\explode('.', $path) as $segment) {
            $next = [];
            foreach ($current as $currentType) {
                $owner = $byType[$currentType] ?? null;
                if ($owner === null) {
                    continue;
                }
                foreach ($owner->relations() as $relation) {
                    if ($relation->name() === $segment) {
                        foreach ($relation->relatedTypes() as $relatedType) {
                            $next[$relatedType] = true;
                        }
                    }
                }
            }
            if ($next === []) {
                return [];
            }
            $current = \array_keys($next);
        }

        return $current;
    }

    /**
     * The single `page` query parameter. JSON:API carries the whole `page[…]`
     * family under one key, so it projects as one OAS `deepObject` parameter
     * (`style: deepObject, explode: true` — the wire form `page[number]=…` is
     * byte-identical to before), whose schema each paginator self-describes via
     * {@see \haddowg\JsonApi\Pagination\PaginatorInterface::describePageSchema()}: a
     * plain object for a single strategy, a `oneOf` menu for a
     * {@see \haddowg\JsonApi\Pagination\MultiPaginator}. A `null` schema means the
     * collection is unpaginated — no `page` parameter at all (§4.4).
     *
     * A paginator's page schema may carry a schema-level `x-profile` marker (the
     * cursor strategy does — see {@see \haddowg\JsonApi\Pagination\CursorPaginator::describePageSchema()}).
     * The marker is emitted STATICALLY by the registration-blind paginator VO, so here
     * — the one place that knows the registered set — any `x-profile` whose profile the
     * server did not register is stripped (top level and each `oneOf` branch). Only the
     * marker is registration-gated: the branch/parameter itself always stays (cursor
     * pagination works without the profile registered).
     *
     * @return list<Parameter>
     */
    private function pageParameters(?Schema $pageSchema, ServerMetadataInterface $server): array
    {
        if ($pageSchema === null) {
            return [];
        }

        $pageSchema = $this->stripUnregisteredProfileMarkers($pageSchema, $server->profiles());

        return [
            Parameter::query(
                'page',
                $pageSchema,
                'Pagination parameters, e.g. `page[number]=2&page[size]=10`.',
                style: ParameterStyle::DeepObject,
                explode: true,
            ),
        ];
    }

    /**
     * Strips any page-schema `x-profile` marker naming an UNREGISTERED profile — at the
     * top level (a bare cursor page) and in each `oneOf` branch (a MultiPaginator menu,
     * whose cursor arm carries the marker). A marker naming a registered profile is
     * kept. The schema is otherwise untouched: the cursor branch stays regardless.
     *
     * @param list<string> $profiles
     */
    private function stripUnregisteredProfileMarkers(Schema $pageSchema, array $profiles): Schema
    {
        $pageSchema = $this->stripProfileMarker($pageSchema, $profiles);

        $oneOf = $pageSchema->get('oneOf');
        if (\is_array($oneOf)) {
            $branches = [];
            foreach ($oneOf as $branch) {
                if ($branch instanceof Schema) {
                    $branches[] = $this->stripProfileMarker($branch, $profiles);
                }
            }
            if ($branches !== []) {
                $pageSchema = $pageSchema->withOneOf($branches);
            }
        }

        return $pageSchema;
    }

    /**
     * Removes a schema's `x-profile` marker when it names a profile absent from the
     * registered `$profiles`; a no-op otherwise.
     *
     * @param list<string> $profiles
     */
    private function stripProfileMarker(Schema $schema, array $profiles): Schema
    {
        $marker = $schema->extension('profile');
        if (\is_string($marker) && !\in_array($marker, $profiles, true)) {
            return $schema->withoutExtension('profile');
        }

        return $schema;
    }

    /**
     * The `withCount` parameter for an endpoint that honours the Countable profile — a
     * comma-separated list of count tokens (`_self_` and/or countable relation names),
     * exactly as `?include` is a comma list (OAS `form`/`explode: false` array). `null`
     * when no token is valid for the endpoint (nothing countable), so the parameter is
     * advertised only where the runtime would honour it. Registration-gated: the runtime
     * recognises `withCount` only when the Countable profile is **registered** and
     * negotiated, so a server that did not register the Countable profile advertises no
     * `withCount` at all — advertising it there would document a parameter every request
     * `400`s on.
     *
     * @param list<string> $tokens
     */
    private function withCountParameter(array $tokens, ServerMetadataInterface $server): ?Parameter
    {
        if ($tokens === [] || !\in_array(CountableProfile::URI, $server->profiles(), true)) {
            return null;
        }

        $schema = Schema::ofType('array')
            ->withItems(Schema::ofType('string')->withEnum($tokens));

        return Parameter::query(
            'withCount',
            $schema,
            'A comma-separated list of relationship-count tokens (the Countable profile). '
            . '`_self_` counts this collection; a relation name counts that relation per item. '
            . 'Recognised only when the Countable profile is negotiated. Allowed tokens: `'
            . \implode('`, `', $tokens) . '`.',
            style: ParameterStyle::Form,
            explode: false,
        )->withExtension('profile', CountableProfile::URI);
    }

    /**
     * A `$ref` to the shared `relatedQuery` parameter component for a primary read
     * endpoint (`GET /{type}` and `GET /{type}/{id}`), or `null` when the affordance
     * does not apply: the Relationship Queries profile must be **registered** AND the
     * primary type must declare at least one relation to address from the primary
     * request. Registration-gated for the same reason as `?withCount` — the runtime
     * parses the `relatedQuery` family only under the negotiated profile, so advertising
     * it unregistered would document a `400`. Every eligible endpoint references the
     * SAME single component ({@see relatedQueryParameterComponent()}); the allowed
     * paths/keys are validated per relationship at runtime, not enumerated per endpoint.
     */
    private function relatedQueryParameter(TypeMetadataInterface $type, ServerMetadataInterface $server): ?Reference
    {
        if ($type->relations() === [] || !\in_array(RelationshipQueriesProfile::URI, $server->profiles(), true)) {
            return null;
        }

        return Reference::to('parameters', RelationshipQueriesProfile::FAMILY);
    }

    /**
     * The single reusable `relatedQuery` parameter component (the Relationship Queries
     * profile), emitted once under `#/components/parameters/relatedQuery` by the
     * {@see OpenApiProjector} and `$ref`d by every relation-bearing primary read endpoint
     * ({@see relatedQueryParameter()}).
     *
     * ONE generic `deepObject` shape for the whole
     * `relatedQuery[<path>][sort]` / `relatedQuery[<path>][filter][<key>]` family: an
     * object keyed by relationship (include) path, each value an object with an optional
     * `sort` string and `filter` map (the same shape as the primary `?filter`). The
     * allowed relationship paths and filter/sort keys are deliberately NOT enumerated —
     * the addressed relationship's own vocabulary validates them at runtime. Only the
     * canonical {@see RelationshipQueriesProfile::FAMILY} is projected; the byte-identical
     * `rQ` shorthand is an undocumented convenience alias (documenting both would duplicate
     * the parameter with no added meaning, and the two merge with the canonical winning).
     */
    public function relatedQueryParameterComponent(): Parameter
    {
        $perPath = Schema::ofType('object')
            ->withProperty('sort', Schema::ofType('string')->withDescription(
                'A comma-separated list of sort fields for the addressed relationship; prefix a field with `-` for descending order.',
            ))
            ->withProperty('filter', Schema::ofType('object')
                ->withAdditionalProperties(Schema::create())
                ->withDescription('A `filter[<key>]=<value>` map applied to the addressed relationship, in the same shape as the primary `filter`.'))
            ->withAdditionalProperties(false);

        return Parameter::query(
            RelationshipQueriesProfile::FAMILY,
            Schema::ofType('object')->withAdditionalProperties($perPath),
            'Per-relationship sort and filter applied from the primary request, addressed by relationship (include) path (the Relationship Queries profile). '
            . 'e.g. `' . RelationshipQueriesProfile::FAMILY . '[author][sort]=-createdAt` or `'
            . RelationshipQueriesProfile::FAMILY . '[comments][filter][state]=open`. '
            . 'Recognised only when the Relationship Queries profile is negotiated.',
            style: ParameterStyle::DeepObject,
            explode: true,
        )->withExtension('profile', RelationshipQueriesProfile::URI);
    }

    /**
     * The valid `withCount` tokens for a primary collection `GET /{type}`: `_self_` when
     * the type is {@see TypeMetadataInterface::isCountable()} (its collection advertises
     * the count), plus every countable relation of the type (counted per primary item).
     *
     * @return list<string>
     */
    private function collectionWithCountTokens(TypeMetadataInterface $type): array
    {
        $tokens = $type->isCountable() ? ['_self_'] : [];

        return [...$tokens, ...$this->countableRelationNames($type)];
    }

    /**
     * The valid `withCount` tokens for a related to-many `GET /{type}/{id}/{rel}`:
     * `_self_` when the relation is {@see RelationMetadataInterface::isCountable()} (its
     * related collection advertises the count), plus every countable relation of the
     * RELATED type (counted per related item across the page).
     *
     * @return list<string>
     */
    private function relatedWithCountTokens(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        $tokens = $relation->isCountable() ? ['_self_'] : [];

        $relatedType = $this->relatedTypeMetadata($relation, $server);
        $relationNames = $relatedType === null ? [] : $this->countableRelationNames($relatedType);

        return [...$tokens, ...$relationNames];
    }

    /**
     * The valid `withCount` tokens for a relationship (linkage) `GET …/relationships/{rel}`:
     * only `_self_` (when the relation is {@see RelationMetadataInterface::isCountable()}),
     * since the linkage endpoint renders identifiers, not the related resources' own
     * relationships.
     *
     * @return list<string>
     */
    private function relationshipWithCountTokens(RelationMetadataInterface $relation): array
    {
        return $relation->isCountable() ? ['_self_'] : [];
    }

    /**
     * The names of a type's countable to-many relations, in declaration order — the
     * relation tokens a `?withCount` accepts for that type.
     *
     * @return list<string>
     */
    private function countableRelationNames(TypeMetadataInterface $type): array
    {
        $names = [];
        foreach ($type->relations() as $relation) {
            if ($relation->isCountable()) {
                $names[] = $relation->name();
            }
        }

        return $names;
    }

    /**
     * Flattens parameter groups into one list, dropping the `null`s a single-or-none
     * helper (`sortParameter`/`includeParameter`/`relatedQueryParameter`) returns when its
     * source is empty. A group entry may be a {@see Reference} (the shared `relatedQuery`
     * parameter component) as well as an inline {@see Parameter}.
     *
     * @param list<Parameter|Reference|null> ...$groups
     * @return list<Parameter|Reference>
     */
    private function concatParameters(array ...$groups): array
    {
        $parameters = [];
        foreach ($groups as $group) {
            foreach ($group as $parameter) {
                if ($parameter !== null) {
                    $parameters[] = $parameter;
                }
            }
        }

        return $parameters;
    }

    /**
     * The shared `{id}` path parameter for the resource-scoped endpoints. When the
     * type constrains its id (an encoded or ULID id, the same constraint that drives
     * the `{id}` route requirement), the schema advertises that pattern — fully
     * anchored, since the router accepts only a complete match — so a documented id
     * and an accepted id cannot diverge.
     */
    private function idPathParameter(TypeMetadataInterface $type): Parameter
    {
        $schema = Schema::ofType('string');

        $pattern = $type->idPattern();
        if ($pattern !== null && $pattern !== '') {
            $schema = $schema->withPattern('^(?:' . $pattern . ')$');
        }

        return Parameter::path(
            'id',
            $schema,
            'The `' . $type->type() . '` resource identifier.',
        );
    }

    // ---- Responses / security ---------------------------------------------------

    /**
     * Appends `401` to an operation's error statuses when the operation carries a
     * security requirement: an authenticated firewall returns `401` for a missing or
     * invalid credential (the unauthenticated twin of the `403` a denied-but-authenticated
     * caller gets), so a secured operation can produce both. An unsecured operation
     * (`$security === null`) is unaffected.
     *
     * @param list<string>                   $statuses
     * @param list<SecurityRequirement>|null $security        the per-operation requirement, or `null` when the operation inherits the document default
     * @param list<SecurityRequirement>      $defaultSecurity the document-level default
     *
     * @return list<string>
     */
    private function authStatuses(array $statuses, ?array $security, array $defaultSecurity): array
    {
        // The operation's EFFECTIVE security: its per-operation requirement when set
        // (an explicit `[]` is a public override), else the inherited document default.
        // A non-empty effective requirement means an unauthenticated caller can get a
        // `401` (the firewall / security layer returns it).
        $effective = $security ?? $defaultSecurity;

        return $effective === [] ? $statuses : [...$statuses, '401'];
    }

    /**
     * Adds the enumerated standard error responses (D12), each referencing the shared
     * error-document component. Statuses are supplied per operation by the caller.
     *
     * @param list<string> $statuses
     */
    private function withErrorResponses(Responses $responses, array $statuses): Responses
    {
        $errorRef = Reference::to('schemas', 'ErrorDocument');
        foreach ($statuses as $status) {
            $responses = $responses->with($status, Response::ofSchema(
                self::STATUS_DESCRIPTIONS[$status] ?? 'Error',
                $errorRef,
            ));
        }

        return $responses;
    }

    /**
     * The per-operation security requirement: the document-level
     * {@see ServerMetadataInterface::defaultSecurity()} when this operation is in the
     * type's secured-operations set, otherwise `null` (inherit the document default —
     * no per-operation `security` emitted). Mirrors the action `isSecured()` intent;
     * the requirement VOs come only from the configured default (§4.6 / D8).
     *
     * An **empty** configured default ({@see ServerMetadataInterface::defaultSecurity()}
     * is `[]`) carries nothing to attach: a secured operation then emits no
     * per-operation `security` (returns `null`, inheriting the equally-empty document
     * default) rather than `security: []`, which in OAS 3.1 actively declares auth
     * *optional* — the opposite of the secured intent.
     *
     * An operation explicitly declared **public** ({@see TypeMetadataInterface::publicOperations()})
     * returns an empty requirement (`[]`) — the projector emits the OAS "no auth"
     * operation override `security: []`, opting it out of the document default
     * regardless of what that default is.
     *
     * @return list<SecurityRequirement>|null
     */
    private function securityFor(TypeMetadataInterface $type, OperationType $operation, ServerMetadataInterface $server): ?array
    {
        if (\in_array($operation, $type->publicOperations(), true)) {
            return [];
        }

        if (!\in_array($operation, $type->securedOperations(), true)) {
            return null;
        }

        return $this->configuredSecurity($server);
    }

    /**
     * The security requirement for one of a relation's endpoints, OVERRIDING the
     * owning type's projected requirement with the relation's own declared security:
     *
     * - `false` ⇒ the relation is explicitly public — emit `security: []` (the OAS
     *   "no auth" override), regardless of the parent.
     * - a string or `true` ⇒ the relation is secured — emit the configured document
     *   security (mirrors {@see configuredSecurity()}); the expression itself is a
     *   runtime concern not projected into the document.
     * - `null` ⇒ the relation declares no security of its own, so it inherits the
     *   parent operation's projected requirement ({@see securityFor()}).
     *
     * @return list<SecurityRequirement>|null
     */
    private function relationSecurityFor(string|bool|null $relationSecurity, TypeMetadataInterface $type, OperationType $parentOperation, ServerMetadataInterface $server): ?array
    {
        if ($relationSecurity === false) {
            return [];
        }

        if ($relationSecurity === null) {
            return $this->securityFor($type, $parentOperation, $server);
        }

        return $this->configuredSecurity($server);
    }

    /**
     * The configured per-operation security requirement, or `null` when the document
     * default is empty — so a secured operation never emits the intent-inverting
     * `security: []`. Shared by CRUD/relationship operations and custom actions.
     *
     * @return list<SecurityRequirement>|null
     */
    private function configuredSecurity(ServerMetadataInterface $server): ?array
    {
        $default = $server->defaultSecurity();

        return $default === [] ? null : $default;
    }

    /**
     * The allowed operations as a presence set keyed by the {@see OperationType}
     * backing value, for O(1) membership checks.
     *
     * @return array<string, true>
     */
    private function allowedOperations(TypeMetadataInterface $type): array
    {
        $set = [];
        foreach ($type->operations() as $operation) {
            $set[$operation->value] = true;
        }

        return $set;
    }

    /**
     * Human-readable descriptions for the enumerated error statuses (D12). Required
     * by the OAS meta-schema (a Response Object's `description` is mandatory). The
     * numeric-string keys are int at runtime; the lookup in {@see withErrorResponses()}
     * coerces its string `$status` to match.
     */
    private const STATUS_DESCRIPTIONS = [
        '400' => 'Bad Request — the request was malformed (e.g. an invalid query parameter).',
        '401' => 'Unauthorized — authentication is required and was missing or invalid.',
        '403' => 'Forbidden — the request is not authorised.',
        '404' => 'Not Found — the resource does not exist.',
        '406' => 'Not Acceptable — the `Accept` header could not be satisfied.',
        '409' => 'Conflict — the request conflicts with the resource state (e.g. a type or id mismatch).',
        '415' => 'Unsupported Media Type — the `Content-Type` header is not `application/vnd.api+json`.',
        '422' => 'Unprocessable Entity — the document failed validation.',
        '500' => 'Internal Server Error.',
    ];
}
