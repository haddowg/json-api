<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

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
        $base = ComponentNaming::base($type->type());

        $parameters = $this->concatParameters(
            $this->filterParameters($type->filters()),
            [$this->sortParameter($type->sorts())],
            [$this->includeParameter($type->includablePaths())],
            $this->fieldsParameters($type, $server, $type->includablePaths()),
            $this->pageParameters($type->paginatorKind()),
        );

        $responses = (new Responses())
            ->with('200', Response::ofSchema(
                'A collection of ' . $type->type() . ' resources.',
                Schema::ref(ComponentNaming::schemaRef($base . 'Collection')),
            ));
        $responses = $this->withErrorResponses($responses, ['400', '403', '406', '500']);

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'List ' . $type->type(),
            operationId: 'fetchCollection.' . $type->type(),
            parameters: $parameters,
            security: $this->securityFor($type, OperationType::FetchCollection, $server),
        );
    }

    private function fetchOneOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $base = ComponentNaming::base($type->type());

        $parameters = $this->concatParameters(
            [$this->includeParameter($type->includablePaths())],
            $this->fieldsParameters($type, $server, $type->includablePaths()),
        );

        $responses = (new Responses())
            ->with('200', Response::ofSchema(
                'The requested ' . $type->type() . ' resource.',
                Schema::ref(ComponentNaming::schemaRef($base . 'Document')),
            ));
        $responses = $this->withErrorResponses($responses, ['400', '403', '404', '406', '500']);

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Fetch a ' . $type->type(),
            operationId: 'fetchOne.' . $type->type(),
            parameters: $parameters,
            security: $this->securityFor($type, OperationType::FetchOne, $server),
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

        $responses = (new Responses())
            ->with('201', new Response(
                'The created ' . $type->type() . ' resource.',
                headers: ['Location' => new Header(
                    'The URL of the created resource.',
                    schema: Schema::ofType('string')->withFormat('uri-reference'),
                )],
                content: [MediaType::JSON_API => MediaType::ofSchema(
                    Schema::ref(ComponentNaming::schemaRef($base . 'Document')),
                )],
            ));
        $responses = $this->withErrorResponses($responses, ['400', '403', '404', '409', '415', '422', '500']);

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Create a ' . $type->type(),
            operationId: 'create.' . $type->type(),
            requestBody: RequestBody::ofSchema($requestSchema),
            security: $this->securityFor($type, OperationType::Create, $server),
        );
    }

    private function updateOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $base = ComponentNaming::base($type->type());

        $requestSchema = $type->hasFields()
            ? Schema::ref(ComponentNaming::schemaRef($base . 'UpdateRequest'))
            : Schema::ref(ComponentNaming::schemaRef($base . 'Resource'));

        $responses = (new Responses())
            ->with('200', Response::ofSchema(
                'The updated ' . $type->type() . ' resource.',
                Schema::ref(ComponentNaming::schemaRef($base . 'Document')),
            ));
        $responses = $this->withErrorResponses($responses, ['400', '403', '404', '409', '415', '422', '500']);

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Update a ' . $type->type(),
            operationId: 'update.' . $type->type(),
            requestBody: RequestBody::ofSchema($requestSchema),
            security: $this->securityFor($type, OperationType::Update, $server),
        );
    }

    private function deleteOperation(TypeMetadataInterface $type, ServerMetadataInterface $server): Operation
    {
        $responses = (new Responses())
            ->with('204', Response::noContent('The resource was deleted.'));
        $responses = $this->withErrorResponses($responses, ['400', '403', '404', '500']);

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Delete a ' . $type->type(),
            operationId: 'delete.' . $type->type(),
            security: $this->securityFor($type, OperationType::Delete, $server),
        );
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
                $this->filterParameters($relation->filters()),
                [$this->sortParameter($relation->sorts())],
                [$includeParameter],
                $fieldsParameters,
                $this->pageParameters($relation->paginatorKind()),
            );
            $successDescription = 'The related ' . $relation->name() . ' collection.';
        } else {
            $responseRef = Schema::ref(ComponentNaming::schemaRef($relBase . 'RelatedDocument'));
            $parameters = $this->concatParameters([$includeParameter], $fieldsParameters);
            $successDescription = 'The related ' . $relation->name() . ' resource (or `null`).';
        }

        $responses = (new Responses())
            ->with('200', Response::ofSchema($successDescription, $responseRef));
        $responses = $this->withErrorResponses($responses, ['400', '403', '404', '406', '500']);

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: 'Fetch the related ' . $relation->name() . ' of a ' . $type->type(),
            operationId: 'fetchRelated.' . $type->type() . '.' . $relation->name(),
            parameters: $parameters,
            // A related read mirrors a fetch — it carries security iff fetch-one does.
            security: $this->securityFor($type, OperationType::FetchOne, $server),
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

        // GET — read the relationship linkage (mirrors a fetch).
        $getResponses = (new Responses())
            ->with('200', Response::ofSchema('The ' . $relation->name() . ' relationship linkage.', $documentRef));
        $operations['get'] = new Operation(
            responses: $this->withErrorResponses($getResponses, ['400', '403', '404', '406', '500']),
            tags: $tags,
            summary: 'Fetch the ' . $relation->name() . ' relationship of a ' . $type->type(),
            operationId: 'fetchRelationship.' . $type->type() . '.' . $relation->name(),
            security: $this->securityFor($type, OperationType::FetchOne, $server),
        );

        // PATCH — full replacement of the relationship.
        if ($relation->allowsReplace()) {
            $operations['patch'] = $this->relationshipMutationOperation(
                $type,
                $relation,
                $server,
                'Replace the ' . $relation->name() . ' relationship of a ' . $type->type(),
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
        string $operationPrefix,
        Schema $documentRef,
    ): Operation {
        $responses = (new Responses())
            ->with('200', Response::ofSchema('The updated ' . $relation->name() . ' relationship linkage.', $documentRef))
            ->with('204', Response::noContent('The relationship was updated.'));
        $responses = $this->withErrorResponses($responses, ['400', '403', '404', '409', '415', '422', '500']);

        return new Operation(
            responses: $responses,
            tags: $type->tags(),
            summary: $summary,
            operationId: $operationPrefix . '.' . $type->type() . '.' . $relation->name(),
            requestBody: RequestBody::ofSchema($documentRef),
            // A relationship mutation mirrors an update — secured iff update is.
            security: $this->securityFor($type, OperationType::Update, $server),
        );
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
     * negotiation), its output → the output type's document schema or a `204`, its
     * tags, and the configured security requirement when {@see isSecured()}.
     */
    private function actionOperation(TypeMetadataInterface $type, ActionMetadataInterface $action, ServerMetadataInterface $server): Operation
    {
        $responses = new Responses();
        $outputType = $action->outputType();
        if ($outputType !== null) {
            $responses = $responses->with('200', Response::ofSchema(
                'The action result.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($outputType) . 'Document')),
            ));
        } else {
            $responses = $responses->with('204', Response::noContent('The action completed with no content.'));
        }
        $responses = $this->withErrorResponses($responses, ['400', '403', '404', '422', '500']);

        return new Operation(
            responses: $responses,
            tags: $action->tags(),
            summary: $action->summary(),
            description: $action->description(),
            operationId: 'action.' . $type->type() . '.' . $action->path(),
            requestBody: $this->actionRequestBody($action),
            security: $action->isSecured() ? $this->configuredSecurity($server) : null,
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

    // ---- Parameters (reused by the stage-B relationship/action projection) ------

    /**
     * One `filter[<key>]` query parameter per declared filter; its value schema is
     * projected from the filter's value constraints (§4.4). A presence-only filter
     * (no constraints) yields a permissive value schema.
     *
     * A {@see \haddowg\JsonApi\Resource\Filter\Range} (and its
     * {@see \haddowg\JsonApi\Resource\Filter\DateRange} specialisation) carries a
     * **structured** value — the nested `filter[<key>][min]`/`[max]` wire shape —
     * so it projects an **object** value schema with `min`/`max` properties rather
     * than the scalar schema its per-bound constraints would otherwise yield
     * (ADR 0076; the `deepObject` *parameter style* itself is a follow-up slice).
     *
     * @param list<\haddowg\JsonApi\Resource\Filter\FilterInterface> $filters
     * @return list<Parameter>
     */
    private function filterParameters(array $filters): array
    {
        $parameters = [];
        foreach ($filters as $filter) {
            $isRange = $filter instanceof \haddowg\JsonApi\Resource\Filter\Range;

            $parameters[] = Parameter::query(
                'filter[' . $filter->key() . ']',
                $this->filterValueSchema($filter),
                $this->filterDescription($filter),
                // A structured Range's nested filter[<key>][min]/[max] value is an
                // OAS `deepObject` parameter (ADR 0077); a scalar filter has no style.
                style: $isRange ? ParameterStyle::DeepObject : null,
                explode: $isRange ? true : null,
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
        if ($filter instanceof \haddowg\JsonApi\Resource\Filter\DescribedFilter) {
            $declared = $filter->getDescription();
            if ($declared !== null && $declared !== '') {
                return $declared;
            }
        }

        return 'Filter the collection by `' . $filter->key() . '`.';
    }

    /**
     * The value schema for one filter's `filter[<key>]` parameter.
     *
     * A scalar filter projects its declared value constraints. A structured
     * {@see \haddowg\JsonApi\Resource\Filter\Range} instead projects an `object`
     * with optional `min`/`max` bound properties: each bound carries the range's
     * declared per-bound constraints (a numeric pattern for `Range`), and a
     * {@see \haddowg\JsonApi\Resource\Filter\DateRange}'s bounds are `string`s with
     * `format: date-time` (ADR 0076, spec §6).
     */
    private function filterValueSchema(\haddowg\JsonApi\Resource\Filter\FilterInterface $filter): Schema
    {
        if (!$filter instanceof \haddowg\JsonApi\Resource\Filter\Range) {
            return $this->schemaProjector->projectConstraints($filter->constraints());
        }

        $bound = $filter instanceof \haddowg\JsonApi\Resource\Filter\DateRange
            ? Schema::ofType('string')->withFormat('date-time')
            : $this->schemaProjector->projectConstraints($filter->constraints());

        return Schema::ofType('object')
            ->withProperties(['min' => $bound, 'max' => $bound]);
    }

    /**
     * The single `sort` parameter: a comma-separated string whose enumerable tokens
     * are each sortable key and its `-`-prefixed descending form (§4.4). Returns
     * `null` when the type declares no sorts.
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

        $schema = Schema::ofType('string')->withEnum($tokens);

        return Parameter::query(
            'sort',
            $schema,
            'A comma-separated list of sort fields. Prefix a field with `-` for descending order. Allowed tokens: `'
            . \implode('`, `', $tokens) . '`.',
        );
    }

    /**
     * The single `include` parameter: a comma-separated string of the type's allowed
     * includable relationship paths (§4.4). Returns `null` when nothing is includable.
     *
     * @param list<string> $includablePaths
     */
    private function includeParameter(array $includablePaths): ?Parameter
    {
        if ($includablePaths === []) {
            return null;
        }

        $schema = Schema::ofType('string')->withEnum($includablePaths);

        return Parameter::query(
            'include',
            $schema,
            'A comma-separated list of relationship paths to include in a compound document. Allowed paths: `'
            . \implode('`, `', $includablePaths) . '`.',
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
        $parameters = [];
        foreach ($this->reachableFieldTypes($type, $server, $includablePaths) as $reachableType) {
            $parameters[] = Parameter::query(
                'fields[' . $reachableType . ']',
                Schema::ofType('string'),
                'A comma-separated list of `' . $reachableType . '` fields to return (sparse fieldsets).',
            );
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
                $parameters[] = Parameter::query(
                    'fields[' . $reachableType . ']',
                    Schema::ofType('string'),
                    'A comma-separated list of `' . $reachableType . '` fields to return (sparse fieldsets).',
                );
            }
        }

        return $parameters;
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
     * The paginator-kind-specific `page[…]` parameters: `number`/`size` for
     * {@see PaginatorKind::Page}, `offset`/`limit` for {@see PaginatorKind::Offset},
     * `cursor`/`size` for {@see PaginatorKind::Cursor}, and none for
     * {@see PaginatorKind::None} (§4.4).
     *
     * @return list<Parameter>
     */
    private function pageParameters(PaginatorKind $kind): array
    {
        return match ($kind) {
            PaginatorKind::Page => [
                Parameter::query('page[number]', Schema::ofType('integer')->withMinimum(1), 'The page number to retrieve.'),
                Parameter::query('page[size]', Schema::ofType('integer')->withMinimum(1), 'The number of resources per page.'),
            ],
            PaginatorKind::Offset => [
                Parameter::query('page[offset]', Schema::ofType('integer')->withMinimum(0), 'The zero-based offset of the first resource.'),
                Parameter::query('page[limit]', Schema::ofType('integer')->withMinimum(1), 'The maximum number of resources to return.'),
            ],
            PaginatorKind::Cursor => [
                Parameter::query('page[cursor]', Schema::ofType('string'), 'An opaque cursor marking the page to retrieve.'),
                Parameter::query('page[size]', Schema::ofType('integer')->withMinimum(1), 'The number of resources per page.'),
            ],
            PaginatorKind::None => [],
        };
    }

    /**
     * Flattens parameter groups into one list, dropping the `null`s a single-or-none
     * helper (`sortParameter`/`includeParameter`) returns when its source is empty.
     *
     * @param list<Parameter|null> ...$groups
     * @return list<Parameter>
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
     * The shared `{id}` path parameter for the resource-scoped endpoints.
     */
    private function idPathParameter(TypeMetadataInterface $type): Parameter
    {
        return Parameter::path(
            'id',
            Schema::ofType('string'),
            'The `' . $type->type() . '` resource identifier.',
        );
    }

    // ---- Responses / security ---------------------------------------------------

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
     * @return list<SecurityRequirement>|null
     */
    private function securityFor(TypeMetadataInterface $type, OperationType $operation, ServerMetadataInterface $server): ?array
    {
        if (!\in_array($operation, $type->securedOperations(), true)) {
            return null;
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
        '403' => 'Forbidden — the request is not authorised.',
        '404' => 'Not Found — the resource does not exist.',
        '406' => 'Not Acceptable — the `Accept` header could not be satisfied.',
        '409' => 'Conflict — the request conflicts with the resource state (e.g. a type or id mismatch).',
        '415' => 'Unsupported Media Type — the `Content-Type` header is not `application/vnd.api+json`.',
        '422' => 'Unprocessable Entity — the document failed validation.',
        '500' => 'Internal Server Error.',
    ];
}
