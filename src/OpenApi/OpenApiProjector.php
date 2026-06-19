<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

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
            tags: $server->tags(),
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

        return $paths;
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
        // object schema, never a broken empty one).
        if ($type->hasFields()) {
            $schemas[$name . 'Attributes'] = $this->schemaProjector->projectAttributes($fields, false, $collector);
            $resource = $this->schemaProjector->projectResourceObject($type->type(), $fields, false, $collector);
        } else {
            $resource = $this->permissiveResourceObject($type->type());
        }
        $resource = $this->withRelationshipsProperty($resource, $type);
        $schemas[$name . 'Resource'] = $resource;

        $schemas[$name . 'ResourceIdentifier'] = $this->resourceIdentifierSchema($type->type());

        // Write request document schemas (create requires/allows id per the policy;
        // update never carries a writable id beyond the path identifier).
        if ($type->hasFields()) {
            $schemas[$name . 'CreateRequest'] = $this->createRequestSchema($type, $collector);
            $schemas[$name . 'UpdateRequest'] = $this->updateRequestSchema($type, $collector);
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

        // Document envelopes.
        $schemas[$name . 'Document'] = $this->singleDocumentSchema($name);
        $schemas[$name . 'Collection'] = $this->collectionDocumentSchema($name);
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
                        $schemas[$collection] = $this->collectionDocumentSchema($relName);
                    }
                }
            }
        }
    }

    // ---- Resource-level schemas -------------------------------------------------

    /**
     * A permissive resource object for a type with no declared field inventory: the
     * `type` const + a string `id`, with open attributes / relationships / meta.
     *
     * Like the field-backed resource object this is a **response** shape, so it
     * requires both `type` and `id` (JSON:API 1.1 §7.2).
     */
    private function permissiveResourceObject(string $type): Schema
    {
        return Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('attributes', Schema::ofType('object'))
            ->withProperty('meta', Schema::ofType('object'))
            ->withRequired(['type', 'id']);
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

    private function createRequestSchema(TypeMetadataInterface $type, EnumComponentCollector $collector): Schema
    {
        $resource = Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type->type()))
            ->withProperty('attributes', $this->schemaProjector->projectAttributes($type->fields(), true, $collector));

        if ($type->allowsClientId()) {
            $resource = $resource->withProperty('id', Schema::ofType('string'));
        }
        if ($type->relations() !== []) {
            $resource = $resource->withProperty('relationships', Schema::ofType('object'));
        }
        $resource = $resource->withRequired(['type']);

        return $this->writeDocumentEnvelope($resource);
    }

    private function updateRequestSchema(TypeMetadataInterface $type, EnumComponentCollector $collector): Schema
    {
        $resource = Schema::ofType('object')
            ->withProperty('type', Schema::ofType('string')->withConst($type->type()))
            ->withProperty('id', Schema::ofType('string'))
            ->withProperty('attributes', $this->schemaProjector->projectAttributes($type->fields(), false, $collector));

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
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('data', $this->linkageData($relation))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
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
        return Schema::ofType('object')
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withProperty('data', $this->nullable($this->relatedResourceSchema($relation)))
            ->withProperty('included', $this->includedSchema())
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
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
        return Schema::ofType('object')
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withProperty('data', Schema::ofType('array')->withItems($this->relatedResourceSchema($relation)))
            ->withProperty('included', $this->includedSchema())
            ->withProperty('links', Schema::ref('#/components/schemas/PaginationLinks'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
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
    private function singleDocumentSchema(string $base): Schema
    {
        return Schema::ofType('object')
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withProperty('data', Schema::ref('#/components/schemas/' . $base . 'Resource'))
            ->withProperty('included', $this->includedSchema())
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withRequired(['data']);
    }

    /**
     * The resource-collection document: `{data: [<Resource>], included?, links
     * (pagination), meta?, jsonapi?}`.
     */
    private function collectionDocumentSchema(string $base): Schema
    {
        return Schema::ofType('object')
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withProperty('data', Schema::ofType('array')->withItems(Schema::ref('#/components/schemas/' . $base . 'Resource')))
            ->withProperty('included', $this->includedSchema())
            ->withProperty('links', Schema::ref('#/components/schemas/PaginationLinks'))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
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
            ->withProperty('jsonapi', Schema::ref('#/components/schemas/JsonApi'))
            ->withProperty('errors', Schema::ofType('array')->withItems(Schema::ref('#/components/schemas/Error'))->withMinItems(1))
            ->withProperty('meta', Schema::ref('#/components/schemas/Meta'))
            ->withProperty('links', Schema::ref('#/components/schemas/Links'))
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
