<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Projects a server's worth of JSON:API metadata (a {@see ServerMetadataInterface})
 * into an OpenAPI 3.1 {@see OpenApi} document — the **skeleton** (openapi / info /
 * servers / tags / security schemes), the **component set** and the **document
 * envelopes** (design §4.3), plus the **named reusable enum components** (§4.8).
 *
 * This slice (Slice 2) deliberately emits **no `paths`** — the path / operation
 * projection (every CRUD + relationship + action operation, its parameters,
 * request bodies and responses) is Slice 3. A path-less OAS 3.1 document is valid:
 * the spec requires at least one of `paths` / `components` / `webhooks`, and the
 * projector always emits `components`.
 *
 * It is a **pure** projector (no I/O, no Symfony): it composes the Slice-1
 * {@see SchemaProjector} (field / constraint → schema, with enum-component hoisting
 * via an {@see EnumComponentCollector}) and the OAS VO model. Component **names**
 * follow stable conventions so the Slice-3 path projection can `$ref` them.
 */
final class OpenApiProjector
{
    public function __construct(
        private readonly SchemaProjector $schemaProjector = new SchemaProjector(),
        private readonly OperationProjector $operationProjector = new OperationProjector(),
    ) {}

    /**
     * Builds the OpenAPI document for `$server`.
     */
    public function project(ServerMetadataInterface $server): OpenApi
    {
        $schemas = [];
        $this->addSharedComponents($schemas, $server->jsonApiVersion());

        // Seed the collector with the shared component names already in `$schemas`
        // so a backed enum whose short name clashes with one (`Meta`, `Links`, …) is
        // gracefully disambiguated (`Meta2`) rather than overwriting it.
        $collector = new EnumComponentCollector(\array_keys($schemas));

        foreach ($server->types() as $type) {
            $this->addTypeComponents($schemas, $type, $collector);
        }

        // Linkage `$ref`s a `<RelatedType>ResourceIdentifier` (and an exposed related
        // endpoint `$ref`s its `Resource`/`Collection`) for every relation's related
        // type, but a related type need not itself be a registered type (the contract
        // does not require it). Emit minimal components for any referenced-but-
        // unregistered related type so the document carries no dangling internal
        // reference (the OAS meta-schema treats Schema Objects as opaque and cannot
        // catch one).
        $this->addUnregisteredRelatedComponents($schemas, $server);

        // The Atomic Operations extension (opt-in): when enabled, its request/result
        // document components join the schema set (and its path joins the document
        // below). The polymorphic operation/result `data` schemas reference the
        // participating types' resource components already emitted above.
        if ($server->atomicOperations() !== null) {
            $this->addAtomicComponents($schemas, $server);
        }

        // The hoisted enum components are known only after every type is projected.
        // The collector was seeded with the shared names and dedupes its own enums, so
        // the only remaining way `$name` is already taken is a collision with a
        // per-type component name (`<Type>Resource`, etc.) — astronomically unlikely,
        // but silent data loss would be worse than a loud, actionable error. The
        // enum's `$ref` is already baked into `$name`, so it cannot be renamed here.
        foreach ($collector->components() as $name => $schema) {
            if (isset($schemas[$name])) {
                throw new \LogicException(\sprintf(
                    'The hoisted enum component "%s" collides with a generated component of the same name. Rename the enum to avoid the clash.',
                    $name,
                ));
            }
            $schemas[$name] = $schema;
        }
        \ksort($schemas);

        $components = new Components(
            schemas: $schemas,
            securitySchemes: $server->securitySchemes(),
        );

        $document = new OpenApi(
            info: $this->info($server),
            components: $components,
            servers: $server->servers(),
            security: $server->defaultSecurity(),
            tags: $this->tags($server),
            externalDocs: $server->externalDocs(),
        );

        $paths = $this->paths($server);

        return $paths->isEmpty() ? $document : $document->withPaths($paths);
    }

    /**
     * Assembles the document {@see Paths} by projecting each type's allowed CRUD
     * operations (stage A) into {@see PathItem}s, grouped by URL template. A path
     * already produced by an earlier type cannot collide (uriTypes are unique), so a
     * simple `with()` accumulation is correct.
     */
    private function paths(ServerMetadataInterface $server): Paths
    {
        $paths = new Paths();
        foreach ($server->types() as $type) {
            foreach ($this->operationProjector->projectType($type, $server) as $path => $item) {
                $paths = $paths->with($path, $item);
            }
        }

        // The Atomic Operations extension endpoint (when enabled) — a single
        // batch-write path, projected after the per-type CRUD paths.
        $atomic = $server->atomicOperations();
        if ($atomic !== null) {
            $paths = $paths->with($atomic->path(), $this->atomicPathItem($atomic));
        }

        return $paths;
    }

    /**
     * The document-root tag list, with the Atomic Operations tag unioned in (and
     * defined) when the extension is enabled and the configured tags do not already
     * declare it — so the atomic operation's `tags` always resolves to a defined
     * document-root tag.
     *
     * @return list<Tag>
     */
    private function tags(ServerMetadataInterface $server): array
    {
        $tags = $server->tags();

        $atomic = $server->atomicOperations();
        if ($atomic === null) {
            return $tags;
        }

        foreach ($tags as $tag) {
            if ($tag->name === $atomic->tag()) {
                return $tags;
            }
        }

        $tags[] = new Tag($atomic->tag(), 'The JSON:API Atomic Operations extension (transactional batch writes).');

        return $tags;
    }

    private function info(ServerMetadataInterface $server): Info
    {
        return new Info(
            title: $server->title(),
            version: $server->version(),
            description: $server->description(),
            contact: $server->contact(),
            license: $server->license(),
        );
    }

    /**
     * The components shared by every document: the JSON:API object, the top-level
     * links / meta containers, and the error document.
     *
     * @param array<string, Schema> $schemas
     */
    private function addSharedComponents(array &$schemas, string $jsonApiVersion): void
    {
        $schemas['JsonApi'] = $this->jsonApiObjectSchema($jsonApiVersion);
        $schemas['Meta'] = Schema::ofType('object')
            ->withDescription('A JSON:API meta object: a free-form set of non-standard members.')
            ->withAdditionalProperties(Schema::create());
        $schemas['LinkObject'] = $this->linkObjectSchema();
        $schemas['Links'] = $this->topLevelLinksSchema();
        $schemas['PaginationLinks'] = $this->paginationLinksSchema();
        $schemas['ErrorSource'] = $this->errorSourceSchema();
        $schemas['Error'] = $this->errorObjectSchema();
        $schemas['ErrorDocument'] = $this->errorDocumentSchema();
    }

    /**
     * Adds one type's component set: attributes, resource object, resource
     * identifier, create / update request schemas, the per-relationship relationship
     * objects, and the single / collection / relationship / compound document
     * envelopes.
     *
     * @param array<string, Schema> $schemas
     */
    private function addTypeComponents(array &$schemas, TypeMetadataInterface $type, EnumComponentCollector $collector): void
    {
        $name = $this->componentBase($type->type());
        $fields = $type->fields();

        // Attributes + resource object (a fieldless standalone type gets a permissive
        // object schema, never a broken empty one). Three context-correct attributes
        // components are emitted and `$ref`'d — they cannot share one schema because a
        // field's visibility (read-only / write-only) and the `required` set differ by
        // representation:
        //   - `<Type>Attributes`        (read)   — the resource object.
        //   - `<Type>CreateAttributes`  (create) — the create request + atomic add.
        //   - `<Type>UpdateAttributes`  (update) — the update request + atomic update.
        if ($type->hasFields()) {
            $schemas[$name . 'Attributes'] = $this->schemaProjector->projectAttributes($fields, RepresentationContext::Read, $collector);
            $schemas[$name . 'CreateAttributes'] = $this->schemaProjector->projectAttributes($fields, RepresentationContext::Create, $collector);
            $schemas[$name . 'UpdateAttributes'] = $this->schemaProjector->projectAttributes($fields, RepresentationContext::Update, $collector);
            $resource = $this->schemaProjector->projectResourceObject($type->type(), $fields, $collector, $type->description(), '#/components/schemas/' . $name . 'Attributes');
        } else {
            $resource = $this->permissiveResourceObject($type->type(), $type->description());
        }
        $resource = $this->withRelationshipsProperty($resource, $type);
        $schemas[$name . 'Resource'] = $resource;

        $schemas[$name . 'ResourceIdentifier'] = $this->resourceIdentifierSchema($type->type());

        // Write request document schemas (create requires/allows id per the policy;
        // update never carries a writable id beyond the path identifier). Both `$ref`
        // the shared attributes components above.
        if ($type->hasFields()) {
            $schemas[$name . 'CreateRequest'] = $this->createRequestSchema($type);
            $schemas[$name . 'UpdateRequest'] = $this->updateRequestSchema($type);
        }

        // Per-relationship relationship-object schemas + the relationship/related
        // document envelopes the relationship & related endpoints (path projection)
        // respond with.
        foreach ($type->relations() as $relation) {
            $relBase = $name . $this->componentBase($relation->name());
            $schemas[$relBase . 'Relationship'] = $this->relationshipObjectSchema($relation);

            // The relationship-linkage endpoint's document envelope — emitted whenever
            // the relationship endpoint is exposed (the path projection $ref's it).
            if ($relation->exposesRelationshipEndpoint()) {
                $schemas[$relBase . 'RelationshipDocument'] = $this->relationshipDocumentSchema($relation);
            }

            // The to-one related endpoint renders `data: <Resource> | null` (an empty
            // to-one is `data: null`), so it needs a per-relation nullable related
            // document — but only when that endpoint is actually exposed (a monomorphic
            // to-many related endpoint reuses the related type's collection envelope
            // instead).
            if (!$relation->isToMany() && $relation->exposesRelatedEndpoint()) {
                $schemas[$relBase . 'RelatedDocument'] = $this->relatedToOneDocumentSchema($relation);
            }

            // A **polymorphic** to-many related endpoint cannot reuse a single member's
            // collection (its members span types), so it gets a per-relation related
            // collection whose `data.items` is the `anyOf` of every member resource —
            // mirroring the to-one polymorphic related document. Emitted only when the
            // endpoint is exposed (so component emission tracks path emission exactly).
            if ($relation->isToMany() && $relation->exposesRelatedEndpoint() && \count($relation->relatedTypes()) > 1) {
                $schemas[$relBase . 'RelatedCollection'] = $this->polymorphicRelatedCollectionSchema($relation);
            }
        }

        // Document envelopes. `included` is described only when the type exposes an
        // includable relationship path (else `?include` is rejected and no response
        // carries `included`).
        $includable = $type->includablePaths() !== [];
        $schemas[$name . 'Document'] = $this->singleDocumentSchema($name, $includable);
        $schemas[$name . 'Collection'] = $this->collectionDocumentSchema($name, $includable);
    }

    /**
     * Emits minimal components for every related type referenced by a relation but not
     * registered as a server type (so its own `addTypeComponents()` never ran), so the
     * document carries no dangling `$ref`:
     *
     * - a `<RelatedType>ResourceIdentifier` (linkage target) — always, since every
     *   relationship object `$ref`s it;
     * - a `<RelatedType>Resource` + `<RelatedType>Collection` — only when a relation
     *   exposing its **related** endpoint targets the type, since the path projection
     *   (stage B) then `$ref`s those for the to-one related document / to-many related
     *   collection. The synthesized shapes are permissive (enough to resolve and
     *   self-describe); a registered related type already has concrete ones.
     *
     * @param array<string, Schema> $schemas
     */
    private function addUnregisteredRelatedComponents(array &$schemas, ServerMetadataInterface $server): void
    {
        $registered = [];
        foreach ($server->types() as $type) {
            $registered[$type->type()] = true;
        }

        foreach ($server->types() as $type) {
            foreach ($type->relations() as $relation) {
                foreach ($relation->relatedTypes() as $relatedType) {
                    if (isset($registered[$relatedType])) {
                        continue;
                    }
                    $relName = $this->componentBase($relatedType);

                    $identifier = $relName . 'ResourceIdentifier';
                    if (!isset($schemas[$identifier])) {
                        $schemas[$identifier] = $this->resourceIdentifierSchema($relatedType);
                    }

                    if (!$relation->exposesRelatedEndpoint()) {
                        continue;
                    }

                    $resource = $relName . 'Resource';
                    if (!isset($schemas[$resource])) {
                        $schemas[$resource] = $this->permissiveResourceObject($relatedType);
                    }
                    $collection = $relName . 'Collection';
                    if (!isset($schemas[$collection])) {
                        $schemas[$collection] = $this->collectionDocumentSchema($relName, $relation->relatedIncludablePaths() !== []);
                    }
                }
            }
        }
    }

    // ---- Atomic Operations extension --------------------------------------------

    /**
     * Adds the Atomic Operations extension's request/result document component
     * schemas (the extension is opt-in; only emitted when
     * {@see ServerMetadataInterface::atomicOperations()} is non-`null`):
     *
     * - `AtomicOperationsRequest` — the request document (`{atomic:operations: […],
     *   jsonapi?, meta?}`).
     * - `AtomicOperation` — one operation object (`op` / `ref` / `href` / `data`).
     * - `AtomicResultsResponse` — the response document (`{atomic:results: […],
     *   jsonapi?, meta?, links?}`).
     * - `AtomicResult` — one result fragment (`{data?, meta?}`; an empty `{}` is
     *   valid).
     *
     * The polymorphic `data` schemas (the resource a write operation carries, and
     * the resource/identifier a result returns) `anyOf` over the participating types'
     * already-emitted resource components, so an author sees the concrete shapes
     * rather than an opaque object.
     *
     * @param array<string, Schema> $schemas
     */
    private function addAtomicComponents(array &$schemas, ServerMetadataInterface $server): void
    {
        // `add` and `update` are **discrete** write shapes — they differ in their
        // attributes (an `add` offers the create-context attributes with their
        // `required`; an `update` is partial, no `required`) and in identification (an
        // `add` may carry a client `id` only where the type allows it; an `update`
        // identifies an existing resource by `id`/`lid`). One fused `<Type>AtomicWrite`
        // could express neither precisely, so the two are projected separately.
        foreach ($server->types() as $type) {
            $base = $this->componentBase($type->type());
            $schemas[$base . 'AtomicAdd'] = $this->atomicAddSchema($type);
            $schemas[$base . 'AtomicUpdate'] = $this->atomicUpdateSchema($type);
        }

        $schemas['AtomicOperation'] = $this->atomicOperationSchema($server);
        $schemas['AtomicOperationsRequest'] = $this->atomicOperationsRequestSchema();
        $schemas['AtomicResult'] = $this->atomicResultSchema($server);
        $schemas['AtomicResultsResponse'] = $this->atomicResultsResponseSchema();
    }

    /**
     * The resource object an atomic **`add`** carries: `type` (const), the
     * create-context `attributes` (`<Type>CreateAttributes`, with their `required`),
     * `relationships`, and an optional `lid` to reference the created resource later
     * in the batch. A client `id` is offered **only** where the type allows one
     * ({@see TypeMetadataInterface::allowsClientId()}); there it is mutually exclusive
     * with `lid` (a titled `oneOf`: client id / local id / server-assigned). Where a
     * client id is not allowed, `id` is **forbidden** (a `false` schema) so a server
     * assigns it — exactly mirroring the standalone `<Type>CreateRequest`.
     */
    private function atomicAddSchema(TypeMetadataInterface $type): Schema
    {
        $base = $this->componentBase($type->type());
        $resource = Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type->type()))
            ->withProperty('lid', Schema::ofType('string')->withDescription('A local id assigned to the created resource, referenceable by later operations in the batch.'))
            ->withProperty('attributes', $type->hasFields()
                ? Schema::ref('#/components/schemas/' . $base . 'CreateAttributes')
                : Schema::ofType('object'))
            ->withProperty('relationships', Schema::ofType('object'))
            ->withRequired(['type'])
            ->withDescription('The resource object an `add` operation creates.');

        if ($type->allowsClientId()) {
            // A client `id` is permitted: `id`, `lid`, or neither (server-assigned),
            // never both.
            return $resource
                ->withProperty('id', Schema::ofType('string'))
                ->withOneOf([
                    Schema::create()->withTitle('Client-supplied id')->withRequired(['id']),
                    Schema::create()->withTitle('Local id (lid)')->withRequired(['lid']),
                    Schema::create()->withTitle('Server-assigned id')
                        ->withProperty('id', Schema::never())
                        ->withProperty('lid', Schema::never()),
                ]);
        }

        // No client id: `id` must be absent (the server assigns it); `lid` stays
        // optional, so no further choice is needed.
        return $resource->withProperty('id', Schema::never());
    }

    /**
     * The resource object an atomic **`update`** carries: `type` (const), the
     * **partial** `attributes` (`<Type>UpdateAttributes`, no `required` — an absent
     * member means "no change", as in `<Type>UpdateRequest`), `relationships`, and the target
     * identification by `id` or `lid` (a resource created earlier in the batch), or
     * neither when the operation targets via its `ref`/`href` instead. A titled
     * `oneOf` makes that an explicit choice and rejects a body carrying both `id` and
     * `lid`.
     */
    private function atomicUpdateSchema(TypeMetadataInterface $type): Schema
    {
        $base = $this->componentBase($type->type());

        return Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type->type()))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('lid', Schema::ofType('string')->withDescription('The local id of a resource created earlier in the batch.'))
            ->withProperty('attributes', $type->hasFields()
                ? Schema::ref('#/components/schemas/' . $base . 'UpdateAttributes')
                : Schema::ofType('object'))
            ->withProperty('relationships', Schema::ofType('object'))
            ->withRequired(['type'])
            ->withDescription('The resource object an `update` operation writes.')
            ->withOneOf([
                Schema::create()->withTitle('By id')->withRequired(['id']),
                Schema::create()->withTitle('By local id (lid)')->withRequired(['lid']),
                Schema::create()->withTitle('Targeted by ref/href')
                    ->withDescription('Neither `id` nor `lid` in `data` — the operation targets the resource by its `ref` or `href`.')
                    ->withProperty('id', Schema::never())
                    ->withProperty('lid', Schema::never()),
            ]);
    }

    /**
     * The `AtomicOperationsRequest` document: a required `atomic:operations` array
     * (`minItems: 1`) of {@see atomicOperationSchema()}, plus the optional `jsonapi`
     * and `meta` top-level members.
     */
    private function atomicOperationsRequestSchema(): Schema
    {
        return Schema::ofType('object')
            ->withDescription('The Atomic Operations extension request document: an ordered, all-or-nothing batch of write operations.')
            ->withProperty(AtomicExtension::OPERATIONS_MEMBER, Schema::ofType('array')
                ->withDescription('The ordered list of operations to apply. Applied in array order, all-or-nothing within one transaction.')
                ->withItems(Schema::ref('#/components/schemas/AtomicOperation'))
                ->withMinItems(1))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withRequired([AtomicExtension::OPERATIONS_MEMBER]);
    }

    /**
     * The `AtomicOperation` object: the operation `op` (the required `add`/`update`/
     * `remove` code), the target (`ref` **or** `href` — exactly one), and the `data`
     * payload (modelled as an `anyOf` over the participating types' discrete
     * `<Type>AtomicAdd` / `<Type>AtomicUpdate` shapes and a resource-identifier shape;
     * absent for a `remove`). The id-vs-lid exclusivity is expressed in those shapes'
     * `oneOf`; the ref-vs-href exclusivity stays a runtime constraint described in prose.
     */
    private function atomicOperationSchema(ServerMetadataInterface $server): Schema
    {
        $ref = Schema::ofType('object')
            ->withDescription('A structural reference to the operation\'s target (an alternative to `href`). Identifies a resource by `type` plus exactly one of `id` or `lid`; an optional `relationship` narrows the target to that relationship.')
            ->withProperty('type', Schema::ofType('string'))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('lid', Schema::ofType('string')->withDescription('A local id referencing a resource created earlier in the same batch.'))
            ->withProperty('relationship', Schema::ofType('string'))
            ->withRequired(['type']);

        return Schema::ofType('object')
            ->withDescription('One operation in the batch. The target is given by exactly one of `ref` or `href`.')
            ->withProperty('op', Schema::ofType('string')
                ->withDescription('The operation code.')
                ->withEnum(['add', 'update', 'remove']))
            ->withProperty('ref', $ref)
            ->withProperty('href', Schema::ofType('string')
                ->withFormat('uri-reference')
                ->withDescription('A URI reference to the operation\'s target (an alternative to `ref`).'))
            ->withProperty('data', $this->atomicOperationDataSchema($server))
            ->withRequired(['op']);
    }

    /**
     * The `AtomicResult` object: one entry of the `atomic:results` array. Per the
     * extension a result object carries only `data` and/or `meta` (never `links` or
     * `included`), and an empty result `{}` is valid (a `remove`, or an `update` the
     * server fully applied). The `data` is an `anyOf` over the participating types'
     * resource and resource-identifier shapes.
     */
    private function atomicResultSchema(ServerMetadataInterface $server): Schema
    {
        return Schema::ofType('object')
            ->withDescription('The result fragment of one operation. Carries `data` and/or `meta` only; an empty object `{}` is valid (e.g. a `remove`).')
            ->withProperty('data', $this->atomicResultDataSchema($server))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'));
    }

    /**
     * The `AtomicResultsResponse` document: a required `atomic:results` array of
     * {@see atomicResultSchema()}, plus the optional `jsonapi` / `meta` / `links`
     * top-level members.
     */
    private function atomicResultsResponseSchema(): Schema
    {
        return Schema::ofType('object')
            ->withDescription('The Atomic Operations extension response document: one result fragment per applied operation, in batch order.')
            ->withProperty(AtomicExtension::RESULTS_MEMBER, Schema::ofType('array')
                ->withDescription('The result of each operation, in batch order.')
                ->withItems(Schema::ref('#/components/schemas/AtomicResult')))
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withRequired([AtomicExtension::RESULTS_MEMBER]);
    }

    /**
     * The `data` payload of an atomic **operation**: an `anyOf` of the participating
     * types' discrete **`add`** and **`update`** resource shapes (`<Type>AtomicAdd` /
     * `<Type>AtomicUpdate`), plus the relationship-linkage payload shapes a
     * relationship operation carries — a single identifier (to-one; id or lid), `null`
     * (a to-one cleared), and an array of identifiers (to-many). When the server
     * registers no types, a generic JSON:API resource stands in.
     */
    private function atomicOperationDataSchema(ServerMetadataInterface $server): Schema
    {
        $members = [];
        foreach ($server->types() as $type) {
            // The discrete `add` and `update` resource objects an operation carries.
            $base = $this->componentBase($type->type());
            $members[] = Schema::ref('#/components/schemas/' . $base . 'AtomicAdd');
            $members[] = Schema::ref('#/components/schemas/' . $base . 'AtomicUpdate');
        }

        if ($members === []) {
            $members[] = $this->genericResourceObjectSchema();
        }

        // The relationship-linkage payload shapes: a single identifier (a to-one
        // relationship target, by `id` or `lid`), `null` (a to-one cleared) and an
        // array of identifiers (a to-many). Each references the generic identifier
        // (requiring only `type`) so the union stays valid however many concrete
        // types participate and a `lid`-only identifier validates.
        $members[] = $this->genericResourceIdentifierSchema();
        $members[] = Schema::ofType('null');
        $members[] = Schema::ofType('array')->withItems($this->genericResourceIdentifierSchema());

        return Schema::create()
            ->withDescription('The operation payload. Its shape depends on `op` and the target: a resource object for an `add`/`update`; a resource identifier (or `null`) for a to-one relationship; an array of resource identifiers for a to-many relationship; absent for a resource `remove`.')
            ->withAnyOf($members);
    }

    /**
     * The `data` of an atomic **result**: an `anyOf` of every participating type's
     * resource component (the created/updated resource a result echoes) and its
     * resource-identifier (a relationship result), with a generic fallback when the
     * server has no field-bearing types.
     */
    private function atomicResultDataSchema(ServerMetadataInterface $server): Schema
    {
        $members = [];
        foreach ($server->types() as $type) {
            $base = $this->componentBase($type->type());
            $members[] = Schema::ref('#/components/schemas/' . $base . 'Resource');
            $members[] = Schema::ref('#/components/schemas/' . $base . 'ResourceIdentifier');
        }

        if ($members === []) {
            $members[] = $this->genericResourceObjectSchema();
            $members[] = $this->genericResourceIdentifierSchema();
        }

        return Schema::create()
            ->withDescription('The created or updated resource (or its identifier) the operation returns.')
            ->withAnyOf($members);
    }

    /**
     * A generic JSON:API resource object shape (`type` + `id` + open attributes /
     * relationships / meta), for the fallback when no participating type carries a
     * concrete resource component.
     */
    private function genericResourceObjectSchema(): Schema
    {
        return Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string'))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('lid', Schema::ofType('string'))
            ->withProperty('attributes', Schema::ofType('object'))
            ->withProperty('relationships', Schema::ofType('object'))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['type']);
    }

    /**
     * A generic JSON:API resource-identifier shape (`type` + an id by `id` or `lid`),
     * for the fallback linkage payload.
     */
    private function genericResourceIdentifierSchema(): Schema
    {
        return Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string'))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('lid', Schema::ofType('string'))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['type'])
            // A resource identifier is keyed by exactly one of `id` or `lid`.
            ->withOneOf([
                Schema::create()->withTitle('By id')->withRequired(['id']),
                Schema::create()->withTitle('By local id (lid)')->withRequired(['lid']),
            ]);
    }

    /**
     * The Atomic Operations {@see PathItem}: a single `POST` that consumes an
     * `AtomicOperationsRequest` and returns an `AtomicResultsResponse`, both under the
     * extension-qualified JSON:API media type (`application/vnd.api+json; ext="<URI>"`).
     * Carries the configured atomic tag + security and the standard error responses.
     */
    private function atomicPathItem(AtomicOperationsMetadataInterface $atomic): PathItem
    {
        return (new PathItem())->withOperation('post', $this->atomicOperation($atomic));
    }

    /**
     * The single atomic `POST` operation: an extension-qualified request body
     * ($ref `AtomicOperationsRequest`), a `200` carrying `AtomicResultsResponse` under
     * the same extension media type, the standard error responses, the atomic tag and
     * the configured security.
     */
    private function atomicOperation(AtomicOperationsMetadataInterface $atomic): Operation
    {
        $mediaType = $this->atomicMediaType();

        $requestBody = new RequestBody(
            content: [$mediaType => MediaType::ofSchema(Schema::ref('#/components/schemas/AtomicOperationsRequest'))],
            description: 'The batch of operations. Sent under the extension-qualified `' . MediaType::JSON_API . '; ext="' . AtomicExtension::URI . '"` media type.',
            required: true,
        );

        $responses = (new Responses())
            ->with('200', new Response(
                'The result of each operation, in batch order.',
                content: [$mediaType => MediaType::ofSchema(Schema::ref('#/components/schemas/AtomicResultsResponse'))],
            ));
        $responses = $this->withAtomicErrorResponses($responses);

        $security = $atomic->security();

        return new Operation(
            responses: $responses,
            tags: [$atomic->tag()],
            summary: 'Apply a batch of atomic operations',
            description: 'Applies an ordered batch of write operations all-or-nothing within a single transaction (the JSON:API Atomic Operations extension). '
                . 'Negotiated via the `ext="' . AtomicExtension::URI . '"` media-type parameter on both the request `Content-Type` and the `Accept` header. '
                . 'Either every operation is committed or none is; a failure rolls the whole batch back. The batch commits through a single transactional persister.',
            operationId: 'atomic.operations',
            requestBody: $requestBody,
            security: $security === [] ? null : $security,
        );
    }

    /**
     * The extension-qualified JSON:API media-type key
     * (`application/vnd.api+json; ext="<AtomicExtension::URI>"`) the atomic request and
     * response are carried under.
     */
    private function atomicMediaType(): string
    {
        return MediaType::JSON_API . '; ext="' . AtomicExtension::URI . '"';
    }

    /**
     * Adds the atomic endpoint's standard error responses (each `$ref`ing the shared
     * `ErrorDocument`): `400`/`403`/`404`/`406`/`409`/`415`/`500`, plus `422` for a
     * validation failure within the batch.
     */
    private function withAtomicErrorResponses(Responses $responses): Responses
    {
        $errorRef = Reference::to('schemas', 'ErrorDocument');
        $statuses = [
            '400' => 'Bad Request — the operations document was malformed.',
            '403' => 'Forbidden — the request is not authorised.',
            '404' => 'Not Found — an operation targets a resource that does not exist.',
            '406' => 'Not Acceptable — the `Accept` header did not request the atomic extension.',
            '409' => 'Conflict — an operation conflicts with the resource state (e.g. a type or id mismatch).',
            '415' => 'Unsupported Media Type — the `Content-Type` did not carry the atomic `ext` parameter.',
            '422' => 'Unprocessable Entity — an operation in the batch failed validation. The whole batch is rolled back.',
            '500' => 'Internal Server Error.',
        ];
        foreach ($statuses as $status => $description) {
            $responses = $responses->with((string) $status, Response::ofSchema($description, $errorRef));
        }

        return $responses;
    }

    // ---- Resource-level schemas -------------------------------------------------

    /**
     * A permissive resource object for a type with no declared field inventory: the
     * `type` const + a string `id`, with open attributes / relationships / meta.
     *
     * Like the field-backed resource object this is a **response** shape, so it
     * requires both `type` and `id` (JSON:API 1.1 §7.2).
     */
    private function permissiveResourceObject(string $type, ?string $description = null): Schema
    {
        return Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('attributes', Schema::ofType('object'))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['type', 'id'])
            ->withDescription($description ?? SchemaProjector::resourceObjectDescription($type));
    }

    /**
     * Replaces the resource object's permissive `relationships` placeholder with a
     * concrete `{type: object, properties}` over the type's declared relations, each
     * `$ref`-ing its relationship-object component.
     */
    private function withRelationshipsProperty(Schema $resource, TypeMetadataInterface $type): Schema
    {
        $relations = $type->relations();
        if ($relations === []) {
            return $resource;
        }

        $base = $this->componentBase($type->type());
        $properties = [];
        foreach ($relations as $relation) {
            $component = $base . $this->componentBase($relation->name()) . 'Relationship';
            $properties[$relation->name()] = Schema::ref('#/components/schemas/' . $component);
        }

        return $resource->withProperty('relationships', Schema::ofType('object')->withProperties($properties));
    }

    /**
     * The resource-identifier (linkage) schema: `type` (const for a monomorphic
     * relation, free string otherwise) + a string `id`, with an optional `meta`.
     */
    private function resourceIdentifierSchema(string $type): Schema
    {
        return Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['type', 'id']);
    }

    private function createRequestSchema(TypeMetadataInterface $type): Schema
    {
        $resource = Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type->type()))
            ->withProperty('attributes', Schema::ref('#/components/schemas/' . $this->componentBase($type->type()) . 'CreateAttributes'));

        if ($type->allowsClientId()) {
            $resource = $resource->withProperty('id', Schema::ofType('string'));
        }
        if ($type->relations() !== []) {
            $resource = $resource->withProperty('relationships', Schema::ofType('object'));
        }
        $resource = $resource->withRequired(['type']);

        return $this->writeDocumentEnvelope($resource);
    }

    private function updateRequestSchema(TypeMetadataInterface $type): Schema
    {
        $resource = Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type->type()))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('attributes', Schema::ref('#/components/schemas/' . $this->componentBase($type->type()) . 'UpdateAttributes'));

        if ($type->relations() !== []) {
            $resource = $resource->withProperty('relationships', Schema::ofType('object'));
        }
        $resource = $resource->withRequired(['type', 'id']);

        return $this->writeDocumentEnvelope($resource);
    }

    /**
     * Wraps a write resource object in the `{data: <resource>}` request document.
     */
    private function writeDocumentEnvelope(Schema $resource): Schema
    {
        return Schema::ofType('object')
            ->withProperty('data', $resource)
            ->withRequired(['data']);
    }

    // ---- Relationship schemas ---------------------------------------------------

    /**
     * The relationship-object schema for one relation: `links` + `meta` + the
     * linkage `data` — a single identifier (or `null`) for a to-one, an array for a
     * to-many; a polymorphic relation's identifier is a `oneOf` of its member
     * identifiers.
     */
    private function relationshipObjectSchema(RelationMetadataInterface $relation): Schema
    {
        $schema = Schema::ofType('object')
            ->withProperty('links', Schema::ofType('object'))
            ->withProperty('data', $this->linkageData($relation))
            ->withProperty('meta', Schema::ofType('object'));

        $description = $relation->description();

        return $description !== null && $description !== '' ? $schema->withDescription($description) : $schema;
    }

    /**
     * The linkage `data` member for a relation: an array of identifiers for a to-many,
     * a single nullable identifier for a to-one (the empty to-one is `data: null`).
     */
    private function linkageData(RelationMetadataInterface $relation): Schema
    {
        $identifier = $this->linkageIdentifierSchema($relation);

        return $relation->isToMany()
            ? Schema::ofType('array')->withItems($identifier)
            : $this->nullable($identifier);
    }

    /**
     * The **relationship document** envelope a relationship endpoint
     * (`GET|PATCH|POST|DELETE /{type}/{id}/relationships/{rel}`) responds with:
     * `{jsonapi?, links?, data: <linkage>, meta?}` — the top-level document around the
     * bare linkage (`data` is the array / nullable-identifier of {@see linkageData()}).
     */
    private function relationshipDocumentSchema(RelationMetadataInterface $relation): Schema
    {
        return Schema::ofType('object')
            ->withProperty('data', $this->linkageData($relation))
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withRequired(['data']);
    }

    /**
     * The **related document** envelope a to-one related endpoint
     * (`GET /{type}/{id}/{rel}`) responds with: like the single-resource document but
     * with a **nullable** `data` (an empty to-one renders `data: null`). A monomorphic
     * relation `$ref`s the related type's resource component; a polymorphic one unions
     * each member's resource (`anyOf`); either way widened with `null`.
     */
    private function relatedToOneDocumentSchema(RelationMetadataInterface $relation): Schema
    {
        $schema = Schema::ofType('object')
            ->withProperty('data', $this->nullable($this->relatedResourceSchema($relation)));

        // `included` is described only when the related type exposes an includable path
        // (else `?include` on the related endpoint is rejected and no response carries it).
        if ($relation->relatedIncludablePaths() !== []) {
            $schema = $schema->withProperty('included', $this->includedSchema());
        }

        return $schema
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withRequired(['data']);
    }

    /**
     * The **related collection** envelope a **polymorphic** to-many related endpoint
     * (`GET /{type}/{id}/{rel}`) responds with: like the resource-collection document
     * but with `data.items` the `anyOf` of every member type's resource (a real
     * response may mix members), so each member validates. A monomorphic to-many reuses
     * the related type's plain `<RelatedType>Collection` instead and never reaches here.
     */
    private function polymorphicRelatedCollectionSchema(RelationMetadataInterface $relation): Schema
    {
        $schema = Schema::ofType('object')
            ->withProperty('data', Schema::ofType('array')->withItems($this->relatedResourceSchema($relation)));

        // A polymorphic to-many declares no shared related includable path, so `included`
        // is described only when the relation actually exposes one.
        if ($relation->relatedIncludablePaths() !== []) {
            $schema = $schema->withProperty('included', $this->includedSchema());
        }

        return $schema
            ->withProperty('links', Schema::ref('#/components/schemas/PaginationLinks'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withRequired(['data']);
    }

    /**
     * A relation's related **resource** schema (not linkage): a `$ref` to the single
     * related type's resource component for a monomorphic relation, a `oneOf` of every
     * member's resource for a polymorphic one, and a permissive object for a relation
     * declaring no related types.
     */
    private function relatedResourceSchema(RelationMetadataInterface $relation): Schema
    {
        $types = $relation->relatedTypes();
        if ($types === []) {
            return Schema::ofType('object');
        }

        if (\count($types) === 1) {
            return Schema::ref('#/components/schemas/' . $this->componentBase($types[0]) . 'Resource');
        }

        $members = [];
        foreach ($types as $relatedType) {
            $members[] = Schema::ref('#/components/schemas/' . $this->componentBase($relatedType) . 'Resource');
        }

        return Schema::create()->withAnyOf($members);
    }

    /**
     * A relation's linkage identifier: a `$ref` to the single related type's
     * identifier component for a monomorphic relation, or a `oneOf` of every member
     * type's identifier for a polymorphic one. A relation declaring no related types
     * degrades to a permissive identifier shape.
     */
    private function linkageIdentifierSchema(RelationMetadataInterface $relation): Schema
    {
        $types = $relation->relatedTypes();
        if ($types === []) {
            return Schema::ofType('object')
                ->withProperty('type', Schema::ofType('string'))
                ->withProperty('id', Schema::ofType('string'))
                ->withRequired(['type', 'id']);
        }

        if (\count($types) === 1) {
            return Schema::ref('#/components/schemas/' . $this->componentBase($types[0]) . 'ResourceIdentifier');
        }

        $members = [];
        foreach ($types as $relatedType) {
            $members[] = Schema::ref('#/components/schemas/' . $this->componentBase($relatedType) . 'ResourceIdentifier');
        }

        return Schema::create()->withAnyOf($members);
    }

    /**
     * Makes a schema nullable. A `$ref` (which carries no `type` to widen) is unioned
     * with the null type — the OAS-3.1 idiom for a nullable referenced schema; a
     * schema with a scalar `type` is widened in place.
     */
    private function nullable(Schema $schema): Schema
    {
        if ($schema->hasScalarType()) {
            return $schema->asNullable();
        }

        return Schema::create()->withAnyOf([$schema, Schema::ofType('null')]);
    }

    // ---- Document envelopes -----------------------------------------------------

    /**
     * The single-resource document: `{data: <Resource>, included?, links?, meta?,
     * jsonapi?}`.
     *
     * The `data` member is a **non-nullable** resource reference: this envelope
     * describes a primary single-resource fetch (`GET /{type}/{id}`), where JSON:API
     * mandates a present resource object (a `null` primary `data` is only valid for an
     * empty to-one *related* endpoint, which is described separately by the
     * relationship-object schema). (Design recon 5b.)
     */
    private function singleDocumentSchema(string $base, bool $includable): Schema
    {
        $schema = Schema::ofType('object')
            ->withProperty('data', Schema::ref('#/components/schemas/' . $base . 'Resource'));

        // The compound-document `included` member is only describable when the type has
        // an includable relationship path — otherwise `?include` is rejected and a
        // response can never carry `included`, so the schema must not advertise it.
        if ($includable) {
            $schema = $schema->withProperty('included', $this->includedSchema());
        }

        return $schema
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withRequired(['data']);
    }

    /**
     * The resource-collection document: `{data: [<Resource>], included?, links
     * (pagination), meta?, jsonapi?}`.
     */
    private function collectionDocumentSchema(string $base, bool $includable): Schema
    {
        $schema = Schema::ofType('object')
            ->withProperty('data', Schema::ofType('array')->withItems(Schema::ref('#/components/schemas/' . $base . 'Resource')));

        if ($includable) {
            $schema = $schema->withProperty('included', $this->includedSchema());
        }

        return $schema
            ->withProperty('links', Schema::ref('#/components/schemas/PaginationLinks'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withRequired(['data']);
    }

    /**
     * The `included` member of a compound document — an array of (any) resource
     * objects. A permissive item shape keeps the envelope type-agnostic (the
     * concrete reachable types are a Slice-3 path concern).
     */
    private function includedSchema(): Schema
    {
        return Schema::ofType('array')->withItems(
            Schema::ofType('object')
                ->withProperty('type', Schema::ofType('string'))
                ->withProperty('id', Schema::ofType('string'))
                ->withRequired(['type', 'id']),
        );
    }

    // ---- Shared schemas ---------------------------------------------------------

    /**
     * The `jsonapi` object schema (`{version, ext, profile, meta}`), pinning the
     * `version` property to the server's configured JSON:API version: a
     * server-generated response document always carries that version, and `const`
     * only constrains when the optional `jsonapi` member is present.
     */
    private function jsonApiObjectSchema(string $jsonApiVersion): Schema
    {
        return Schema::ofType('object')
            ->withDescription("An object describing the server's implementation of the JSON:API specification.")
            ->withProperty('version', Schema::ofType('string')->withConst($jsonApiVersion))
            ->withProperty('ext', Schema::ofType('array')->withItems(Schema::ofType('string')->withFormat('uri')))
            ->withProperty('profile', Schema::ofType('array')->withItems(Schema::ofType('string')->withFormat('uri')))
            ->withProperty('meta', Schema::ofType('object'));
    }

    /**
     * A JSON:API link: either a URI string, or a link object `{href, ...}`.
     */
    private function linkObjectSchema(): Schema
    {
        $object = Schema::ofType('object')
            ->withProperty('href', Schema::ofType('string')->withFormat('uri-reference'))
            ->withProperty('rel', Schema::ofType('string'))
            ->withProperty('title', Schema::ofType('string'))
            ->withProperty('type', Schema::ofType('string'))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['href']);

        return Schema::create()
            ->withDescription('A JSON:API link: a string URI, a link object, or null (e.g. an absent pagination link).')
            ->withAnyOf([
                Schema::ofType('string')->withFormat('uri-reference'),
                $object,
                Schema::ofType('null'),
            ]);
    }

    /**
     * The top-level `links` member: `self` / `related` plus arbitrary further links.
     */
    private function topLevelLinksSchema(): Schema
    {
        return Schema::ofType('object')
            ->withProperty('self', Schema::ref('#/components/schemas/LinkObject'))
            ->withProperty('related', Schema::ref('#/components/schemas/LinkObject'))
            ->withAdditionalProperties(Schema::ref('#/components/schemas/LinkObject'));
    }

    /**
     * The collection-level `links`: pagination `first` / `prev` / `next` / `last`
     * (each nullable) plus `self`.
     */
    private function paginationLinksSchema(): Schema
    {
        $link = Schema::ref('#/components/schemas/LinkObject');

        return Schema::ofType('object')
            ->withProperty('self', $link)
            ->withProperty('first', $link)
            ->withProperty('prev', $link)
            ->withProperty('next', $link)
            ->withProperty('last', $link);
    }

    /**
     * The shared error-document schema: `{errors: [<Error>], meta?, jsonapi?, links?}`,
     * mirroring core's {@see \haddowg\JsonApi\Schema\Error\Error} object shape.
     */
    private function errorDocumentSchema(): Schema
    {
        return Schema::ofType('object')
            ->withProperty('errors', Schema::ofType('array')->withItems(Schema::ref('#/components/schemas/Error'))->withMinItems(1))
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withRequired(['errors']);
    }

    /**
     * A single JSON:API error object (every member optional, per the spec), matching
     * core's {@see \haddowg\JsonApi\Schema\Error\Error}.
     */
    private function errorObjectSchema(): Schema
    {
        return Schema::ofType('object')
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('status', Schema::ofType('string'))
            ->withProperty('code', Schema::ofType('string'))
            ->withProperty('title', Schema::ofType('string'))
            ->withProperty('detail', Schema::ofType('string'))
            ->withProperty('source', Schema::ref('#/components/schemas/ErrorSource'))
            ->withProperty('links', Schema::ofType('object'))
            ->withProperty('meta', Schema::ofType('object'));
    }

    /**
     * The error `source` member: `pointer` / `parameter` / `header`, matching core's
     * {@see \haddowg\JsonApi\Schema\Error\ErrorSource}.
     */
    private function errorSourceSchema(): Schema
    {
        return Schema::ofType('object')
            ->withProperty('pointer', Schema::ofType('string'))
            ->withProperty('parameter', Schema::ofType('string'))
            ->withProperty('header', Schema::ofType('string'));
    }

    // ---- Naming -----------------------------------------------------------------

    /**
     * A PascalCase component-name base from a JSON:API type/member name (e.g.
     * `blog-post` → `BlogPost`, `author` → `Author`), so component names are stable
     * and idiomatic. Delegates to the shared {@see ComponentNaming} so the path
     * projection names the identical components.
     */
    private function componentBase(string $name): string
    {
        return ComponentNaming::base($name);
    }
}
