<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\ActionDeclarations;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponses;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresWriteOnlyRelation;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationResponseInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;

/**
 * Projects a type's HTTP surface into OpenAPI {@see PathItem}s — the full
 * path/operation projection:
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
 *   bodies and per-action security.
 *
 * Each operation enumerates its concrete query parameters, request body and standard
 * error responses, and carries its tags ({@see TypeMetadataInterface::tags()})
 * plus the configured per-operation security requirement.
 *
 * It is a **pure** projector (no I/O, no Symfony): it composes the
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
     * relationship reads and action paths (a standalone serializer with only actions,
     * say), but never a relationship mutation — that verb rides on the type's `Update`.
     *
     * @return array<string, PathItem> path template → {@see PathItem}
     */
    public function projectType(TypeMetadataInterface $type, ServerMetadataInterface $server): array
    {
        ActionDeclarations::validateType($type);

        $operations = OperationEnumerator::forType($type);

        $paths = [];
        foreach ($operations as $operation) {
            $item = $paths[$operation->path] ?? (OperationEnumerator::isResourceScoped($operation)
                ? new PathItem(parameters: [$this->idPathParameter($type)])
                : new PathItem());
            $paths[$operation->path] = $item->withOperation($operation->method, $this->operation($type, $operation, $server));
        }

        if ($type instanceof DeclaresOperationExtensions) {
            $paths = $this->withDeclaredExtensions($type, $operations, $paths);
        }

        return $paths;
    }

    /**
     * Stamps the type's declared vendor extensions on each of its operations: the
     * type-wide set everywhere, with a CRUD operation's own set merged over it.
     *
     * @param list<EnumeratedOperation> $operations
     * @param array<string, PathItem>   $paths
     *
     * @return array<string, PathItem>
     */
    private function withDeclaredExtensions(DeclaresOperationExtensions $type, array $operations, array $paths): array
    {
        $typeWide = $type->extensions();

        foreach ($operations as $enumerated) {
            $crudOperation = $enumerated->key->kind->operationType();
            $extensions = $crudOperation !== null
                ? [...$typeWide, ...$type->operationExtensions($crudOperation)]
                : $typeWide;

            // An action's own extensions are already on its operation, and win.
            $item = $paths[$enumerated->path];
            $operation = $item->operations[$enumerated->method];
            if ($extensions !== []) {
                $paths[$enumerated->path] = $item->withOperation($enumerated->method, $operation->withExtensions([...VendorExtensions::normalise($extensions), ...$operation->extensions()]));
            }
        }

        return $paths;
    }

    /**
     * Builds one enumerated operation.
     */
    private function operation(TypeMetadataInterface $type, EnumeratedOperation $operation, ServerMetadataInterface $server): Operation
    {
        $relation = $operation->relation;
        $action = $operation->action;
        $id = $operation->operationId;
        $tags = $operation->tags;
        $scopes = OperationScopes::of($server)?->forOperation($operation) ?? [];

        return match (true) {
            $operation->key->kind === OperationKind::FetchCollection => $this->fetchCollectionOperation($type, $server, $id, $tags, $scopes),
            $operation->key->kind === OperationKind::Create => $this->createOperation($type, $server, $id, $tags, $scopes),
            $operation->key->kind === OperationKind::FetchOne => $this->fetchOneOperation($type, $server, $id, $tags, $scopes),
            $operation->key->kind === OperationKind::Update => $this->updateOperation($type, $server, $id, $tags, $scopes),
            $operation->key->kind === OperationKind::Delete => $this->deleteOperation($type, $server, $id, $tags, $scopes),
            $action !== null => $this->actionOperation($type, $action, $server, $scopes)->withOperationId($id),
            $relation === null => throw new \LogicException(\sprintf('The "%s" operation names no relation.', $operation->key->name())),
            $operation->key->kind === OperationKind::FetchRelated => $this->relatedOperation($type, $relation, $server, $id, $tags, $scopes),
            $operation->key->kind === OperationKind::FetchRelationship => $this->relationshipFetchOperation($type, $relation, $server, $id, $tags, $scopes),
            default => $this->relationshipMutationOperation($type, $relation, $operation->key->kind, $server, $id, $tags, $scopes),
        };
    }

    // ---- Operations -------------------------------------------------------------

    /**
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function fetchCollectionOperation(TypeMetadataInterface $type, ServerMetadataInterface $server, string $operationId, array $tags, array $scopes): Operation
    {
        $parameters = $this->concatParameters(
            $this->filterParameters($type->filters(), $type->fields()),
            [$this->sortParameter($type->sorts())],
            $this->documentShapeParameters($type, $server),
            $this->pageParameters($type->pageSchema(), $server),
            [$this->withCountParameter($this->collectionWithCountTokens($type), $server)],
            [$this->relatedQueryParameter($type, $server)],
        );

        $security = $this->securityFor($type, OperationType::FetchCollection, $server);

        $security = $this->scoped($security, $scopes, $server);

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::FetchCollection) as $response) {
            $responses = $responses->with((string) $response->status(), $this->fetchCollectionSuccessResponse($type, $response));
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $tags,
            summary: 'List ' . TypeNoun::of($type)->plural,
            description: $this->crudOperationDescription($type, OperationType::FetchCollection),
            operationId: $operationId,
            parameters: $parameters,
            security: $security,
        );
    }

    /**
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function fetchOneOperation(TypeMetadataInterface $type, ServerMetadataInterface $server, string $operationId, array $tags, array $scopes): Operation
    {
        $parameters = $this->concatParameters(
            $this->documentShapeParameters($type, $server),
            [$this->relatedQueryParameter($type, $server)],
        );

        $security = $this->securityFor($type, OperationType::FetchOne, $server);

        $security = $this->scoped($security, $scopes, $server);

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::FetchOne) as $response) {
            $responses = $responses->with((string) $response->status(), $this->fetchOneSuccessResponse($type, $response));
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $tags,
            summary: 'Fetch ' . TypeNoun::of($type)->withArticle(),
            description: $this->crudOperationDescription($type, OperationType::FetchOne),
            operationId: $operationId,
            parameters: $parameters,
            security: $security,
        );
    }

    /**
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function createOperation(TypeMetadataInterface $type, ServerMetadataInterface $server, string $operationId, array $tags, array $scopes): Operation
    {
        $base = ComponentNaming::base($type->type());

        // A standalone serializer-only type (no field inventory) carries no
        // create-request component; fall back to the permissive write envelope ref.
        $requestSchema = $type->hasFields()
            ? Schema::ref(ComponentNaming::schemaRef($base . 'CreateRequest'))
            : Schema::ref(ComponentNaming::schemaRef($base . 'Resource'));

        $declared = $type->responsesFor(OperationType::Create);

        $responses = new Responses();
        foreach ($declared as $response) {
            $responses = $responses->with((string) $response->status(), $this->createSuccessResponse($type, $response, $server));
        }
        $security = $this->securityFor($type, OperationType::Create, $server);
        $security = $this->scoped($security, $scopes, $server);
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '409', '415', '422', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $tags,
            summary: 'Create ' . TypeNoun::of($type)->withArticle(),
            description: $this->crudOperationDescription($type, OperationType::Create),
            operationId: $operationId,
            parameters: $this->declaresStatus($declared, 201) ? $this->documentShapeParameters($type, $server) : [],
            requestBody: RequestBody::ofSchema($requestSchema),
            security: $security,
        );
    }

    /**
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function updateOperation(TypeMetadataInterface $type, ServerMetadataInterface $server, string $operationId, array $tags, array $scopes): Operation
    {
        $base = ComponentNaming::base($type->type());

        $requestSchema = $type->hasFields()
            ? Schema::ref(ComponentNaming::schemaRef($base . 'UpdateRequest'))
            : Schema::ref(ComponentNaming::schemaRef($base . 'Resource'));

        $declared = $type->responsesFor(OperationType::Update);

        $responses = new Responses();
        foreach ($declared as $response) {
            $responses = $responses->with((string) $response->status(), $this->updateSuccessResponse($type, $response, $server));
        }
        $security = $this->securityFor($type, OperationType::Update, $server);
        $security = $this->scoped($security, $scopes, $server);
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '409', '415', '422', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $tags,
            summary: 'Update ' . TypeNoun::of($type)->withArticle(),
            description: $this->crudOperationDescription($type, OperationType::Update),
            operationId: $operationId,
            parameters: $this->declaresStatus($declared, 200) ? $this->documentShapeParameters($type, $server) : [],
            requestBody: RequestBody::ofSchema($requestSchema),
            security: $security,
        );
    }

    /**
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function deleteOperation(TypeMetadataInterface $type, ServerMetadataInterface $server, string $operationId, array $tags, array $scopes): Operation
    {
        $security = $this->securityFor($type, OperationType::Delete, $server);
        $security = $this->scoped($security, $scopes, $server);

        $responses = new Responses();
        foreach ($type->responsesFor(OperationType::Delete) as $response) {
            $responses = $responses->with((string) $response->status(), $this->deleteSuccessResponse($type, $response));
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $tags,
            summary: 'Delete ' . TypeNoun::of($type)->withArticle(),
            description: $this->crudOperationDescription($type, OperationType::Delete),
            operationId: $operationId,
            security: $security,
        );
    }

    // ---- Per-operation success responses ----------------------------------------

    /**
     * The concrete {@see Response} for one declared create success response. `201` is
     * the created-resource shape (with `Location`), `204` a bodyless create, `202` the
     * async-accept shape.
     */
    private function createSuccessResponse(TypeMetadataInterface $type, OperationResponseInterface $response, ServerMetadataInterface $server): Response
    {
        return match ($response->status()) {
            201 => new Response(
                'The created ' . TypeNoun::of($type)->singular . '.',
                headers: ['Location' => new Header(
                    'The URL of the created resource.',
                    schema: Schema::ofType('string')->withFormat('uri-reference'),
                )],
                content: [MediaType::JSON_API => MediaType::ofSchema(
                    Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Document')),
                )],
            ),
            204 => Response::noContent('The resource was created; no content is returned.'),
            202 => $this->acceptedResponse($this->jobTypeOf($response), $server),
            default => throw $this->unexpectedResponse(OperationType::Create, $response->status()),
        };
    }

    /**
     * The concrete {@see Response} for one declared update success response. `200` is
     * the updated-resource shape, `204` a bodyless update, `202` the async-accept shape.
     */
    private function updateSuccessResponse(TypeMetadataInterface $type, OperationResponseInterface $response, ServerMetadataInterface $server): Response
    {
        return match ($response->status()) {
            200 => Response::ofSchema(
                'The updated ' . TypeNoun::of($type)->singular . '.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Document')),
            ),
            204 => Response::noContent('The resource was updated; no content is returned.'),
            202 => $this->acceptedResponse($this->jobTypeOf($response), $server),
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
                'The requested ' . TypeNoun::of($type)->singular . '.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Document')),
            ),
            303 => $this->seeOtherResponse($response instanceof SeeOther ? $response->description : null),
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
                'A collection of ' . TypeNoun::of($type)->plural . '.',
                Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . 'Collection')),
            ),
            default => throw $this->unexpectedResponse(OperationType::FetchCollection, $response->status()),
        };
    }

    /**
     * The `202 Accepted` async-accept response: the pollable `$jobType` job document,
     * with `Content-Location` (the poll URL) and `Retry-After` (the poll hint) headers.
     */
    private function acceptedResponse(string $jobType, ServerMetadataInterface $server): Response
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
            content: [MediaType::JSON_API => MediaType::ofSchema($this->documentSchemaOf($jobType, $server))],
        );
    }

    /**
     * The `303 See Other` async-completion response: a `Location` header pointing at
     * the produced resource, no body.
     */
    private function seeOtherResponse(?string $description = null): Response
    {
        return new Response(
            $description ?? 'The asynchronous operation is complete; follow Location to the produced resource.',
            headers: ['Location' => new Header(
                'The URL of the produced resource.',
                schema: Schema::ofType('string')->withFormat('uri-reference'),
            )],
        );
    }

    /**
     * An action's `303`, answering any request that does not prefer JSON:API: `Location`
     * is the URL the action produced, and the response is never cached.
     */
    private function actionSeeOtherResponse(SeeOther $declared): Response
    {
        return new Response(
            $declared->description ?? 'Redirects to the URL the action produced.',
            headers: [
                'Location' => new Header(
                    'The URL the action produced.',
                    required: true,
                    schema: Schema::ofType('string')->withFormat('uri-reference'),
                ),
                ...self::noStoreHeader(),
            ],
        );
    }

    /**
     * An action's `200` location document, answering a request that prefers JSON:API.
     * The schema is the shared `LocationDocument`, narrowed to the declared media type's
     * `type`. `$declared200` is a `200` JSON:API body the action also declares, which the
     * location document joins in a `oneOf`.
     */
    private function actionLocationResponse(SeeOther $declared, string $mediaType, ?Response $declared200): Response
    {
        $schema = Schema::create()->withAllOf([
            Schema::ref(ComponentNaming::schemaRef('LocationDocument')),
            Schema::ofType('object')->withProperty(
                'links',
                Schema::ofType('object')->withProperty(
                    'location',
                    Schema::ofType('object')->withProperty('type', Schema::ofType('string')->withConst($mediaType)),
                ),
            ),
        ]);

        $existing = $declared200?->content[MediaType::JSON_API]->schema ?? null;
        if ($declared200 !== null && $existing !== null) {
            return $declared200
                ->withDescription($declared200->description . ' ' . ($declared->description ?? 'Or the URL the action produced, as its `location` link.'))
                ->withMediaType(MediaType::JSON_API, MediaType::ofSchema(Schema::create()->withOneOf([
                    $existing instanceof Schema ? $existing : Schema::ref($existing->ref),
                    $schema,
                ])))
                ->withHeaders([...$declared200->headers, ...self::noStoreHeader()]);
        }

        return Response::ofSchema($declared->description ?? 'The URL the action produced, as its `location` link.', $schema)->withHeaders(self::noStoreHeader());
    }

    /**
     * @return array{Cache-Control: Header}
     */
    private static function noStoreHeader(): array
    {
        return ['Cache-Control' => new Header(
            '`no-store` unless the action sets its own: the URL may be short-lived.',
            schema: Schema::ofType('string'),
        )];
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

        $noun = TypeNoun::of($type);

        return match ($operation) {
            OperationType::FetchCollection => 'Returns a ' . ($type->pageSchema() !== null ? 'paginated ' : '') . 'collection of ' . $noun->plural . '.',
            OperationType::FetchOne => 'Returns a single ' . $noun->singular . ' by its `id`.',
            OperationType::Create => 'Creates a new ' . $noun->singular . ' from the supplied attributes and relationships.',
            OperationType::Update => 'Updates an existing ' . $noun->singular . ', applying the supplied attributes and relationships.',
            OperationType::Delete => 'Deletes the ' . $noun->singular . ' identified by its `id`.',
        };
    }

    // ---- Relationship & related endpoints -------------------------------------

    /**
     * Whether the relation is declared unconditionally write-only
     * ({@see DeclaresWriteOnlyRelation}).
     */
    private static function isWriteOnly(RelationMetadataInterface $relation): bool
    {
        return $relation instanceof DeclaresWriteOnlyRelation && $relation->isWriteOnly();
    }

    /**
     * The related-resource read operation (`GET /{uriType}/{id}/{rel}`) → a **related
     * document** ($ref the related type's collection for a to-many, the per-relation
     * nullable related document for a to-one). A to-many related collection reuses the
     * CRUD query parameters scoped to the relation's own filters/sorts/pagination/
     * includes.
     *
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function relatedOperation(TypeMetadataInterface $type, RelationMetadataInterface $relation, ServerMetadataInterface $server, string $operationId, array $tags, array $scopes): Operation
    {
        $base = ComponentNaming::base($type->type());
        $relBase = $base . ComponentNaming::base($relation->name());

        // A related endpoint returns the **related** resource(s) as primary data, so its
        // `?include` and `fields[]` are scoped to the related type(s) — not the parent.
        $includeParameter = $this->includeParameter($relation->relatedIncludablePaths());
        $fieldsParameters = $this->relatedFieldsParameters($relation, $server);

        if ($relation->isToMany()) {
            $responseRef = $this->relatedCollectionResponseRef($relBase);
            $parameters = $this->concatParameters(
                $this->relatedFilterParameters($relation, $server),
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
            // vocabulary — any filter `400`s — so it advertises none, even over one type.
            $parameters = $this->concatParameters(
                RelationFacts::monomorphicType($relation) !== null
                    ? $this->relatedFilterParameters($relation, $server)
                    : [],
                [$includeParameter],
                $fieldsParameters,
            );
            $successDescription = 'The related ' . $relation->name() . ' resource.';
        }

        // A related read mirrors a fetch, but the relation's own declared read security
        // OVERRIDES the parent's (a relation may be more *or* less permissive than the
        // type it hangs off).
        $security = $this->relationSecurityFor($relation->securityRead(), $type, OperationType::FetchOne, $server);
        $security = $this->scoped($security, $scopes, $server);

        $responses = (new Responses())
            ->with('200', Response::ofSchema($successDescription, $responseRef));
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $tags,
            summary: 'Fetch the related ' . $relation->name() . ' of ' . TypeNoun::of($type)->withArticle(),
            description: $this->relationDescription(
                $relation,
                $relation->isToMany()
                    ? 'Returns the related `' . $relation->name() . '` resources of ' . TypeNoun::of($type)->withArticle() . '.'
                    : 'Returns the related `' . $relation->name() . '` resource of ' . TypeNoun::of($type)->withArticle() . '.',
            ),
            operationId: $operationId,
            parameters: $parameters,
            // A related read mirrors a fetch — it carries security iff fetch-one does.
            security: $security,
        );
    }

    /**
     * The relationship-linkage read (`GET …/relationships/{rel}`), which mirrors a fetch.
     *
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function relationshipFetchOperation(TypeMetadataInterface $type, RelationMetadataInterface $relation, ServerMetadataInterface $server, string $operationId, array $tags, array $scopes): Operation
    {
        $documentRef = $this->relationshipDocumentRef($type, $relation);
        $parent = TypeNoun::of($type)->withArticle();

        // A **monomorphic to-one** relationship endpoint honours the related resource's
        // `filter[]` vocabulary (a relation filter that excludes the target nulls the
        // linkage). A **monomorphic to-many** relationship endpoint is a real queryable,
        // paginated linkage collection at parity with the related endpoint: `filter[]`/
        // `sort`/`page` (+`withCount` when countable) scope against the same merged
        // vocabulary, the page-1 linkage rendering with the relationship object's
        // pagination links. A **polymorphic** relationship endpoint (to-one or to-many — members
        // span types, no single related provider or shared vocabulary) takes no query
        // parameters: the host rejects a requested `filter`/`sort`/`page` there with a `400`.
        if (!$relation->isToMany()) {
            $getParameters = RelationFacts::monomorphicType($relation) !== null
                ? $this->relatedFilterParameters($relation, $server)
                : [];
        } elseif (RelationFacts::monomorphicType($relation) !== null) {
            $getParameters = $this->concatParameters(
                $this->relatedFilterParameters($relation, $server),
                [$this->sortParameter($this->relatedSortVocabulary($relation, $server))],
                $this->pageParameters($relation->pageSchema(), $server),
                [$this->withCountParameter($this->relationshipWithCountTokens($relation), $server)],
            );
        } else {
            $getParameters = [];
        }
        // The relation's declared read security overrides the parent's fetch gate.
        $getSecurity = $this->scoped($this->relationSecurityFor($relation->securityRead(), $type, OperationType::FetchOne, $server), $scopes, $server);
        $getResponses = (new Responses())
            ->with('200', Response::ofSchema('The ' . $relation->name() . ' relationship linkage.', $documentRef));

        return new Operation(
            responses: $this->withErrorResponses($getResponses, $this->authStatuses(['400', '403', '404', '406', '500'], $getSecurity, $server->defaultSecurity())),
            tags: $tags,
            summary: 'Fetch the ' . $relation->name() . ' relationship of ' . $parent,
            description: $this->relationDescription(
                $relation,
                'Returns the `' . $relation->name() . '` relationship linkage of ' . $parent . '.',
            ),
            operationId: $operationId,
            parameters: $getParameters,
            security: $getSecurity,
        );
    }

    private function relationshipDocumentRef(TypeMetadataInterface $type, RelationMetadataInterface $relation): Schema
    {
        return Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . ComponentNaming::base($relation->name()) . 'RelationshipDocument'));
    }

    /**
     * One relationship-mutation operation (`PATCH`/`POST`/`DELETE` on
     * `…/relationships/{rel}`): a relationship-document request body and a
     * `200` (echoing the linkage) plus the enumerated error responses.
     *
     * It takes no `?include` / `fields[<type>]`: the echoed document is linkage-only, and
     * {@see \haddowg\JsonApi\Transformer\DocumentTransformer::transformRelationshipDataMembers()}
     * emits no `included` member for it however the request is spelled.
     *
     * @param list<string> $tags
     * @param list<string> $scopes the scopes the operation needs
     */
    private function relationshipMutationOperation(
        TypeMetadataInterface $type,
        RelationMetadataInterface $relation,
        OperationKind $kind,
        ServerMetadataInterface $server,
        string $operationId,
        array $tags,
        array $scopes,
    ): Operation {
        $documentRef = $this->relationshipDocumentRef($type, $relation);
        $parent = TypeNoun::of($type)->withArticle();
        [$summary, $defaultDescription] = match ($kind) {
            OperationKind::UpdateRelationship => [
                'Replace the ' . $relation->name() . ' relationship of ' . $parent,
                'Fully replaces the `' . $relation->name() . '` relationship of ' . $parent . ' with the supplied linkage.',
            ],
            OperationKind::AddRelationship => [
                'Add to the ' . $relation->name() . ' relationship of ' . $parent,
                'Adds the supplied members to the `' . $relation->name() . '` relationship of ' . $parent . '.',
            ],
            OperationKind::RemoveRelationship => [
                'Remove from the ' . $relation->name() . ' relationship of ' . $parent,
                'Removes the supplied members from the `' . $relation->name() . '` relationship of ' . $parent . '.',
            ],
            default => throw new \LogicException(\sprintf('"%s" is not a relationship mutation.', $kind->value)),
        };

        // A relationship mutation mirrors an update, but the relation's own declared
        // mutation security OVERRIDES the parent's update gate.
        $security = $this->relationSecurityFor($relation->securityMutate(), $type, OperationType::Update, $server);
        $security = $this->scoped($security, $scopes, $server);

        // The handler echoes the linkage (`200`), except for a write-only relation, whose
        // linkage is never rendered: that mutation answers `204`.
        $responses = self::isWriteOnly($relation)
            ? (new Responses())->with('204', Response::noContent('The ' . $relation->name() . ' relationship was updated.'))
            : (new Responses())->with('200', Response::ofSchema('The updated ' . $relation->name() . ' relationship linkage.', $documentRef));
        $responses = $this->withErrorResponses($responses, $this->authStatuses(['400', '403', '404', '406', '409', '415', '422', '500'], $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $tags,
            summary: $summary,
            description: $this->relationDescription($relation, $defaultDescription),
            operationId: $operationId,
            // A pivot-backed relation's request linkage types `meta.pivot` with the request
            // pivot shape, so it takes its own request document.
            requestBody: RequestBody::ofSchema(RelationFacts::hasPivot($relation)
                ? Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type->type()) . ComponentNaming::base($relation->name()) . 'RelationshipRequest'))
                : $documentRef),
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
     * (`GET /{uriType}/{id}/{rel}`): the per-relation `<Base><Rel>RelatedCollection`
     * document, whose `data.items` is the related resource (the `anyOf` of every member
     * for a polymorphic relation) and whose `links` are the related endpoint's own.
     */
    private function relatedCollectionResponseRef(string $relBase): Schema
    {
        return Schema::ref(ComponentNaming::schemaRef($relBase . 'RelatedCollection'));
    }

    // ---- Custom-action endpoints ----------------------------------------------

    /**
     * One custom action's operation: its input mode → `requestBody`
     * (`None` → none; `Document` → the input type's create-request schema; `Raw` → a
     * permissive binary body under a generic media type with relaxed content-type
     * negotiation), its declared responses ({@see ActionMetadataInterface::responds()})
     * → one success response each, its tags, and the configured security requirement
     * when {@see isSecured()}.
     *
     * @param list<string> $scopes the scopes this method of the action needs
     */
    private function actionOperation(TypeMetadataInterface $type, ActionMetadataInterface $action, ServerMetadataInterface $server, array $scopes): Operation
    {
        $byStatus = [];
        foreach ($action->responds() as $response) {
            if (!$response instanceof RawResult) {
                $byStatus[$response->status()] = $this->actionSuccessResponse($response, $server);
            }
        }
        $location = ActionResponses::location($action->responds());
        if ($location?->mediaType !== null) {
            $byStatus[200] = $this->actionLocationResponse($location, $location->mediaType, $byStatus[200] ?? null);
            \ksort($byStatus);
        }
        foreach ($action->responds() as $response) {
            if ($response instanceof RawResult) {
                $byStatus[$response->status()] = $this->withRawResult($byStatus[$response->status()] ?? new Response('The action result.'), $response);
            }
        }
        $responses = new Responses();
        foreach ($byStatus as $status => $success) {
            $responses = $responses->with((string) $status, $success);
        }

        $security = $this->scoped($action->isSecured() ? $this->configuredSecurity($server) : null, $scopes, $server);

        // Every action negotiates the `Accept` header (`406`); a `None`/`Document` input
        // mode also negotiates the `Content-Type` (`415`), while a `Raw` mode relaxes it
        // unless the action lists the media types it accepts.
        $statuses = ['400', '403', '404', '406', '422', '500'];
        if ($action->inputMode() !== ActionInputMode::Raw || AcceptedBody::of($action) !== []) {
            $statuses[] = '415';
        }
        $responses = $this->withErrorResponses($responses, $this->authStatuses($statuses, $security, $server->defaultSecurity()));

        return new Operation(
            responses: $responses,
            tags: $action->tags(),
            // A custom action carries the author's summary/description when declared, else a
            // generated default — so an action (the least path-inferable operation) is never the
            // only operation left undescribed.
            summary: $action->summary() ?? ('Invoke the `' . $action->path() . '` action'),
            description: $action->description() ?? ('Invokes the `' . $action->path() . '` custom action on ' . ($action->scope() === ActionScope::Resource
                ? TypeNoun::of($type)->withArticle()
                : 'the ' . TypeNoun::of($type)->plural . ' collection') . '.'),
            parameters: $this->actionDocumentShapeParameters($action, $server),
            requestBody: $this->actionRequestBody($action, $server),
            security: $security,
            extensions: self::actionExtensions($action),
        );
    }

    /**
     * The action's own vendor extensions, then its declared safety as `x-safety`, which
     * wins over an extension of the same name.
     *
     * @return array<string, mixed>
     */
    private static function actionExtensions(ActionMetadataInterface $action): array
    {
        $extensions = $action instanceof DeclaresActionExtensions ? VendorExtensions::normalise($action->extensions()) : [];
        $safety = ActionDeclarations::safety($action);
        if ($safety !== []) {
            $extensions['x-safety'] = $safety;
        }

        return $extensions;
    }

    /**
     * The action's request body for its input mode: `None` → no body;
     * `Document` → the input type's create-request schema under the JSON:API media
     * type; `Raw` → a permissive string/binary body under `application/octet-stream`
     * (the author owns the negotiation, so the schema is left open).
     */
    private function actionRequestBody(ActionMetadataInterface $action, ServerMetadataInterface $server): ?RequestBody
    {
        $accepts = AcceptedBody::of($action);
        if ($accepts !== []) {
            return $this->acceptedRequestBody($action, $accepts, $server);
        }

        return match ($action->inputMode()) {
            ActionInputMode::None => null,
            ActionInputMode::Document => $this->actionDocumentRequestBody($action, $server),
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
    private function actionDocumentRequestBody(ActionMetadataInterface $action, ServerMetadataInterface $server): RequestBody
    {
        return RequestBody::ofSchema($this->actionDocumentSchema($action, $server));
    }

    /**
     * The schema of the action's JSON:API request document: the input type's create
     * request, its resource object for a type that cannot be created, or an open object
     * when the action names no registered input type.
     */
    private function actionDocumentSchema(ActionMetadataInterface $action, ServerMetadataInterface $server): Schema
    {
        $inputType = $action->inputType();
        $input = $inputType === null ? null : $this->typeNamed($inputType, $server);
        if ($input === null) {
            return Schema::ofType('object');
        }

        $base = ComponentNaming::base($input->type());

        // `<Type>CreateRequest` exists only for a type with fields that allows create;
        // any other registered type falls back to its resource object, as create does.
        return $input->hasFields() && \in_array(OperationType::Create, $input->operations(), true)
            ? Schema::ref(ComponentNaming::schemaRef($base . 'CreateRequest'))
            : Schema::ref(ComponentNaming::schemaRef($base . 'Resource'));
    }

    /**
     * One `content` entry per accepted body, in declared order (see {@see AcceptedBody}
     * for how each entry's schema is chosen). The body is required only for a `Document`
     * input action, as the input-mode projection has it.
     *
     * @param list<AcceptedBody> $accepts
     */
    private function acceptedRequestBody(ActionMetadataInterface $action, array $accepts, ServerMetadataInterface $server): RequestBody
    {
        $content = [];
        foreach ($accepts as $accepted) {
            $schema = match (true) {
                $accepted->document => $this->actionDocumentSchema($action, $server),
                $accepted->binary => Schema::ofType('string')->withFormat('binary'),
                \is_string($accepted->schema) => Schema::ref(ComponentNaming::schemaRef(ComponentNaming::rawBody($accepted->schema))),
                \is_array($accepted->schema) => Schema::fromArray($accepted->schema),
                default => self::defaultAcceptedSchema($accepted->essence()),
            };
            $content[$accepted->essence()] = MediaType::ofSchema($schema);
        }

        return new RequestBody(
            content: $content,
            description: 'The request body, in one of the media types the action accepts.',
            required: $action->inputMode() === ActionInputMode::Document,
        );
    }

    /**
     * The schema of an accepted body that declares none, by media-type family: text is a
     * string, JSON any JSON value, a form an object, and anything else opaque bytes.
     */
    private static function defaultAcceptedSchema(string $essence): Schema
    {
        [$type, $subtype] = \explode('/', $essence, 2);

        return match (true) {
            $type === 'text' => Schema::ofType('string'),
            $essence === 'application/json', \str_ends_with($subtype, '+json') => Schema::create(),
            $essence === 'multipart/form-data', $essence === 'application/x-www-form-urlencoded' => Schema::ofType('object'),
            default => Schema::ofType('string')->withFormat('binary'),
        };
    }

    /**
     * The single-resource document schema for `$type`: a `$ref` to its `<Type>Document`
     * component when the server registers the type, else an inline document whose
     * `data` is any resource object, so a response never names a component the
     * document doesn't define.
     */
    private function documentSchemaOf(string $type, ServerMetadataInterface $server): Schema
    {
        if ($this->typeNamed($type, $server) !== null) {
            return Schema::ref(ComponentNaming::schemaRef(ComponentNaming::base($type) . 'Document'));
        }

        return Schema::ofType('object')
            ->withProperty('data', Schema::ofType('object')
                ->withProperty('type', Schema::ofType('string')->withConst($type))
                ->withProperty('id', Schema::ofType('string'))
                ->withProperty('attributes', Schema::ofType('object'))
                ->withProperty('relationships', Schema::ofType('object'))
                ->withRequired(['type', 'id']))
            ->withProperty('meta', Schema::ref(ComponentNaming::schemaRef('Meta')))
            ->withProperty('jsonapi', Schema::ref(ComponentNaming::schemaRef('JsonApi')))
            ->withRequired(['data']);
    }

    /**
     * The concrete success {@see Response} for one declared action response:
     * {@see ActionResource} → `200` with that type's document; {@see MetaResult} → `200`
     * meta-only document; {@see NoContent} → `204`; {@see Accepted} → the async `202`;
     * {@see SeeOther} → the plain `303`, or with a media type the `303` to the URL the
     * action produced, beside which {@see actionOperation()} adds the `200` location
     * document.
     */
    private function actionSuccessResponse(ActionResponse $response, ServerMetadataInterface $server): Response
    {
        return match (true) {
            $response instanceof ActionResource => Response::ofSchema(
                'The action result.',
                $this->documentSchemaOf($response->bodyType(), $server),
            ),
            $response instanceof MetaResult => Response::ofSchema(
                'The action result: a meta-only document.',
                Schema::ref(ComponentNaming::schemaRef('MetaDocument')),
            ),
            $response instanceof NoContent => Response::noContent('The action completed with no content.'),
            $response instanceof Accepted => $this->acceptedResponse($response->jobType(), $server),
            $response instanceof SeeOther => $response->mediaType === null
                ? $this->seeOtherResponse($response->description)
                : $this->actionSeeOtherResponse($response),
            default => throw new \LogicException(\sprintf(
                'Unsupported action response %s (status %d).',
                $response::class,
                $response->status(),
            )),
        };
    }

    /**
     * Adds one {@see RawResult} to the success response at its status: its media type
     * joins the `content` map beside any JSON:API body, with its schema and example, and
     * its headers join the response's. A schema class is a `$ref` to the component
     * {@see OpenApiProjector} emits for it.
     */
    private function withRawResult(Response $response, RawResult $raw): Response
    {
        $schema = match (true) {
            $raw->binary => Schema::ofType('string')->withFormat('binary'),
            \is_string($raw->schema) => Schema::ref(ComponentNaming::schemaRef(ComponentNaming::rawBody($raw->schema))),
            \is_array($raw->schema) => Schema::fromArray($raw->schema),
            default => null,
        };
        $media = new MediaType($schema);
        if ($raw->example !== null) {
            $media = $media->withExample($raw->example);
        }
        $response = $response->withMediaType($raw->essence(), $media);

        foreach ($raw->headers as $name => $header) {
            // A Header Object needs a schema; a header value is a string unless the author says otherwise.
            $response = $response->withHeader($name, $header instanceof Header
                ? ($header->schema === null ? new Header($header->description, $header->required, $header->deprecated, Schema::ofType('string')) : $header)
                : new Header(description: $header, schema: Schema::ofType('string')));
        }

        return $response;
    }

    // ---- Parameters (reused by the stage-B relationship/action projection) ------

    /**
     * The `?include` and `fields[<type>]` parameters for an operation whose success body
     * is `$type`'s resource document.
     *
     * The pair shapes the **rendered document**, not the query that selects the data, so
     * it belongs to every operation that answers with a resource document — a `POST`
     * create and a `PATCH` update as much as a `GET`. The runtime treats them the same
     * way: {@see \haddowg\JsonApi\Negotiation\StrictQueryParameterValidator} gates on the
     * family name only, and
     * {@see \haddowg\JsonApi\Transformer\DocumentTransformer::transformResourceDocument()}
     * builds `included` and applies sparse fieldsets without ever consulting the HTTP
     * method. The allowed paths and members are a property of the type, so a write
     * carries the identical enums its reads do.
     *
     * @return list<Parameter>
     */
    private function documentShapeParameters(TypeMetadataInterface $type, ServerMetadataInterface $server): array
    {
        $include = $this->includeParameter($type->includablePaths());

        return [
            ...($include === null ? [] : [$include]),
            ...$this->fieldsParameters($type, $server, $type->includablePaths()),
        ];
    }

    /**
     * The `?include` / `fields[<type>]` parameters for a custom action: the pair for the
     * type named by its {@see ActionResource} response, since that response is rendered
     * as a resource document through the same serializer a read of that type uses. An
     * action answering `204`/`202`/`303`, or a meta-only `200`, has no resource document
     * to shape and takes neither parameter. The parameters are rooted at the **body**
     * type, not the type the action is mounted on — an action on `/articles` returning a
     * `people` document advertises `people`'s include paths.
     *
     * @return list<Parameter>
     */
    private function actionDocumentShapeParameters(ActionMetadataInterface $action, ServerMetadataInterface $server): array
    {
        foreach ($action->responds() as $response) {
            if (!$response instanceof ActionResource) {
                continue;
            }

            $bodyType = $this->typeNamed($response->bodyType(), $server);

            return $bodyType === null ? [] : $this->documentShapeParameters($bodyType, $server);
        }

        return [];
    }

    /**
     * Whether a declared success-response set advertises `$status` — the status whose
     * body is the type's own resource document (`201` for a create, `200` for an
     * update). A set without it answers with no such document: a `204` carries no body
     * at all and a `202`'s body is the job resource, so there is nothing for
     * `?include` / `fields[<type>]` to shape and neither is advertised.
     *
     * @param non-empty-list<OperationResponseInterface> $responses
     */
    private function declaresStatus(array $responses, int $status): bool
    {
        foreach ($responses as $response) {
            if ($response->status() === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * The metadata for the type named `$type`, or `null` when the server does not
     * describe it.
     */
    private function typeNamed(string $type, ServerMetadataInterface $server): ?TypeMetadataInterface
    {
        foreach ($server->types() as $candidate) {
            if ($candidate->type() === $type) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * One `filter[<key>]` query parameter per declared filter. Its **value**
     * schema comes from the filter's declared value constraints; its **container**
     * shape comes from the filter kind. The two compose: the kind says "a list", the
     * constraints say "of integers".
     *
     * A filter with a **structured** wire shape describes its own parameter envelope
     * via {@see \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter}: a
     * {@see \haddowg\JsonApi\Resource\Filter\Range} (and its
     * {@see \haddowg\JsonApi\Resource\Filter\DateRange} specialisation) wraps its
     * per-bound value schema into an **object** with `min`/`max` properties and the
     * OAS `deepObject` style for the nested `filter[<key>][min]`/`[max]` wire shape;
     * a set filter ({@see \haddowg\JsonApi\Resource\Filter\WhereIn} and friends) wraps
     * it into an **array** whose OAS style spells its declared delimiter.
     * A scalar filter (the default) is its value schema with no style — so a
     * consumer-defined structured filter documents correctly with no change here.
     *
     * Where the filter declared **no** value constraints, `$fields` is the fallback:
     * a filter that names the column of one field in the inventory documents as that
     * field's JSON type, and one that names nothing resolvable documents as `string`,
     * the type every query parameter has on the wire
     * ({@see derivedFilterValueType()}).
     *
     * A server-composed group ({@see \haddowg\JsonApi\Resource\Filter\WhereAll} /
     * {@see \haddowg\JsonApi\Resource\Filter\WhereAny}) projects as a single scalar
     * `filter[<key>]` too: a fanning group carries the shared value schema from its
     * own `constraints()`, and an all-fixed group (like a `->fixed()` scalar filter)
     * declares none — a presence parameter whose description notes the value is
     * server-set, see {@see filterDescription()}.
     *
     * A declared `->example(...)` ({@see \haddowg\JsonApi\Resource\Filter\ExemplifiedFilter})
     * becomes the parameter's own `example`, emitted as declared.
     *
     * @param list<\haddowg\JsonApi\Resource\Filter\FilterInterface> $filters
     * @param list<\haddowg\JsonApi\Resource\Field\FieldInterface>   $fields  the inventory of the type the filters run against
     *
     * @return list<Parameter>
     */
    private function filterParameters(array $filters, array $fields): array
    {
        $parameters = [];
        foreach ($filters as $filter) {
            $valueSchema = $this->schemaProjector->projectConstraints($filter->constraints());
            // Only a value the constraints said NOTHING about falls back. One that
            // declared a keyword — even a `format` with no `type` beside it — keeps
            // exactly what it declared, so the fallback can never manufacture a
            // contradiction out of an author's own (possibly deliberate) narrowing.
            if ($valueSchema->toArray() === []) {
                $valueSchema = $valueSchema->withType($this->derivedFilterValueType($filter, $fields) ?? 'string');
            }

            $shape = $filter instanceof \haddowg\JsonApi\Resource\Filter\DescribesQueryParameter
                ? $filter->describeQueryParameter($valueSchema)
                : new QueryParameterShape($valueSchema);

            $parameter = Parameter::query(
                'filter[' . $filter->key() . ']',
                $shape->schema,
                $this->filterDescription($filter),
                style: $shape->style,
                explode: $shape->explode,
            );
            if ($filter instanceof \haddowg\JsonApi\Resource\Filter\ExemplifiedFilter && $filter->hasExample()) {
                $parameter = $parameter->withExample($filter->getExample());
            }
            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * The JSON type a filter's value carries when its declared constraints said nothing
     * about it, or `null` when nothing narrower can be derived. The caller floors that
     * `null` at `string`, which a query parameter is on the wire whatever the server
     * parses it into.
     *
     * A **presence-triggered** filter is `string` whatever it targets: the server
     * decides the match and discards whatever the request carried, so typing it from a
     * column would tell a client to send something that is never read.
     *
     * Otherwise the filter must name a column ({@see \haddowg\JsonApi\Resource\Filter\TargetsColumn})
     * that **exactly one** field in `$fields` backs, and that field must have a scalar
     * wire form. Anything else derives nothing: a relationship path or a relationship
     * name, a computed value no field stores, a column two fields share, a composite or
     * relation field. A guessed narrower type says something false that a client would
     * then be validated against. See ADR 0055.
     *
     * @param list<\haddowg\JsonApi\Resource\Field\FieldInterface> $fields
     */
    private function derivedFilterValueType(\haddowg\JsonApi\Resource\Filter\FilterInterface $filter, array $fields): ?string
    {
        if ($filter instanceof \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter && $filter->isPresenceTriggered()) {
            return 'string';
        }

        if (!$filter instanceof \haddowg\JsonApi\Resource\Filter\TargetsColumn) {
            return null;
        }

        $column = $filter->targetColumn();
        $match = null;
        foreach ($fields as $field) {
            // A computed field's column is null (it stores nothing), and a field
            // flattened from a to-one relation reads its column off the RELATED object —
            // neither names storage on the type being filtered.
            if ($field->column() !== $column || $field->relatedVia() !== null) {
                continue;
            }
            if ($match !== null) {
                return null;
            }
            $match = $field;
        }

        return $match === null ? null : $this->schemaProjector->scalarValueType($match);
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
     * a sortable key or its `-`-prefixed descending form. JSON:API carries
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
            'A comma-separated list of sort fields. Prefix a field with `-` for descending order.',
            style: ParameterStyle::Form,
            explode: false,
        );
    }

    /**
     * The single `include` parameter: a comma-separated **list** of the type's allowed
     * includable relationship paths. JSON:API carries the list in one parameter,
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
            'A comma-separated list of relationship paths to include in a compound document.',
            style: ParameterStyle::Form,
            explode: false,
        );
    }

    /**
     * The `fields[<type>]` sparse-fieldset parameters: one per type
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
            if (!self::isWriteOnly($relation)) {
                $members[] = $relation->name();
            }
        }

        $items = Schema::ofType('string');
        if ($members !== []) {
            $items = $items->withEnum($members);
        }

        return Parameter::query(
            'fields[' . $reachable->type() . ']',
            Schema::ofType('array')->withItems($items),
            'A comma-separated list of `' . $reachable->type() . '` fields to return (sparse fieldsets).',
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
        $single = RelationFacts::monomorphicType($relation);

        return $single !== null ? $this->typeNamed($single, $server) : null;
    }

    /**
     * The `filter[…]` parameters of a related / relationship endpoint. Both halves of
     * the merged vocabulary run against the **related** type's rows, so its inventory
     * — not the parent's — is what a filter's column resolves against. A polymorphic
     * relation has no single related type, hence no inventory: its (own) filters
     * document untyped.
     *
     * @return list<Parameter>
     */
    private function relatedFilterParameters(RelationMetadataInterface $relation, ServerMetadataInterface $server): array
    {
        return $this->filterParameters(
            $this->relatedFilterVocabulary($relation, $server),
            $this->relatedTypeMetadata($relation, $server)?->fields() ?? [],
        );
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
     * collection is unpaginated — no `page` parameter at all.
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

        $description = 'A comma-separated list of count tokens (the Countable profile), recognised only when the Countable profile is negotiated.';
        if (\in_array(CountableProfile::SELF_TOKEN, $tokens, true)) {
            $description .= ' Request `withCount=' . CountableProfile::SELF_TOKEN . '` to receive the collection total in the top-level `meta.total`'
                . ' (also in `meta.page.total` when the collection is paginated).';
        }
        $relationTokens = \array_values(\array_filter($tokens, static fn(string $token): bool => $token !== CountableProfile::SELF_TOKEN));
        if ($relationTokens !== []) {
            $description .= ' Request a relation name, e.g. `withCount=' . $relationTokens[0] . '`, to receive that relation\'s total on each resource'
                . ' in `relationships.' . $relationTokens[0] . '.meta.total`.';
        }

        return Parameter::query(
            'withCount',
            $schema,
            $description,
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
            if ($relation->isCountable() && !self::isWriteOnly($relation)) {
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
            'The `id` of the ' . TypeNoun::of($type)->singular . '.',
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
     * Adds the enumerated standard error responses, each a `$ref` to the shared
     * `components.responses` entry for its status ({@see ErrorResponseProjector}).
     * Statuses are supplied per operation by the caller.
     *
     * @param list<string> $statuses
     */
    private function withErrorResponses(Responses $responses, array $statuses): Responses
    {
        foreach ($statuses as $status) {
            $responses = $responses->with($status, ErrorResponseProjector::reference($status));
        }

        return $responses;
    }

    /**
     * The per-operation security requirement: the document-level
     * {@see ServerMetadataInterface::defaultSecurity()} when this operation is in the
     * type's secured-operations set, otherwise `null` (inherit the document default —
     * no per-operation `security` emitted). Mirrors the action `isSecured()` intent;
     * the requirement VOs come only from the configured default.
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
     * The operation's security with the scopes it needs named under the scope scheme
     * ({@see OperationScopes::apply()}).
     *
     * @param list<SecurityRequirement>|null $security
     * @param list<string>                   $scopes
     *
     * @return list<SecurityRequirement>|null
     */
    private function scoped(?array $security, array $scopes, ServerMetadataInterface $server): ?array
    {
        $operationScopes = OperationScopes::of($server);

        return $operationScopes === null ? $security : $operationScopes->apply($security, $scopes, $server->defaultSecurity());
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
}
