<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives the **stage-B** path/operation projection — the relationship & related
 * endpoints (gated by per-relation endpoint exposure + mutation flags), the
 * custom-action paths (each input mode), and the envelope refinements (the
 * non-nullable primary single `data` vs the nullable relationship/related-to-one
 * `data`) — from in-core {@see FakeServerMetadata} fixtures (no Symfony), and
 * meta-validates the full document against the vendored OAS 3.1 meta-schema (§10).
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[Group('spec:document-structure')]
final class OperationProjectorRelationshipsAndActionsTest extends TestCase
{
    private const OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    /**
     * A rich `articles` resource exercising every stage-B branch:
     *  - `author`      — to-one, every endpoint exposed (related + relationship);
     *  - `tags`        — to-many, queryable related collection (filter/sort/page), every
     *                    mutation verb allowed, related-scoped includable paths;
     *  - `cover`       — to-one, related endpoint suppressed (relationship-only);
     *  - `locked`      — to-many, read-only relationship (no mutation verbs);
     *  - `attachments` — to-many **polymorphic** (`images`|`videos`), related endpoint
     *                    exposed (the to-many related collection mixes member types);
     *  - actions       — a secured resource-scoped action returning a document, a
     *                    collection-scoped raw-input action, and a document-input action.
     */
    private function richServer(): FakeServerMetadata
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make(), Str::make('title')->required()],
            relations: [
                new FakeRelationMetadata('author', ['people'], false),
                new FakeRelationMetadata(
                    'tags',
                    ['tags'],
                    true,
                    countable: true,
                    paginatorKind: PaginatorKind::Page,
                    filters: [Where::make('label')->describedAs('Filter tags by label.')],
                    sorts: [SortByField::make('label')],
                    // The related endpoint's `?include` is scoped to the RELATED type
                    // (`tags`), not the parent (`articles`) — `tags` includes `articles`.
                    relatedIncludablePaths: ['articles'],
                ),
                new FakeRelationMetadata('cover', ['images'], false, relatedEndpoint: false),
                new FakeRelationMetadata('locked', ['people'], true, allowsReplace: false, allowsAdd: false, allowsRemove: false),
                new FakeRelationMetadata('attachments', ['images', 'videos'], true),
            ],
            tags: ['Articles'],
            securedOperations: [OperationType::Create, OperationType::Update],
            includablePaths: ['author', 'tags'],
            actions: [
                new FakeActionMetadata('publish', ['POST'], ActionScope::Resource, ActionInputMode::None, outputType: 'articles', secured: true, tags: ['Articles'], summary: 'Publish the article'),
                new FakeActionMetadata('import', ['POST'], ActionScope::Collection, ActionInputMode::Raw, tags: ['Articles']),
                new FakeActionMetadata('draft', ['POST'], ActionScope::Resource, ActionInputMode::Document, inputType: 'articles', tags: ['Articles']),
            ],
        );
        $people = FakeTypeMetadata::resource(
            type: 'people',
            fields: [Id::make(), Str::make('name')],
            relations: [new FakeRelationMetadata('company', ['companies'], false)],
            tags: ['People'],
            // The related resource's own filter — honoured on the `author` to-one's
            // related and relationship endpoints (proves the to-one filter projection).
            filters: [Where::make('name')],
        );
        // `tags` declares `articles` as an includable relation so the related-endpoint
        // `?include=articles` and the widened `fields[articles]` both resolve.
        $tags = FakeTypeMetadata::resource(
            type: 'tags',
            fields: [Id::make(), Str::make('label')],
            relations: [new FakeRelationMetadata('articles', ['articles'], true)],
            tags: ['Tags'],
            // The related resource's OWN filter/sort — merged with the relation's
            // `label` filter on the `tags` related endpoint (proves the merge).
            filters: [Where::make('color')],
            sorts: [SortByField::make('id')],
        );
        $images = FakeTypeMetadata::resource(type: 'images', fields: [Id::make(), Str::make('url')], tags: ['Media']);
        $videos = FakeTypeMetadata::resource(type: 'videos', fields: [Id::make(), Str::make('url')], tags: ['Media']);

        return new FakeServerMetadata(
            title: 'Blog API',
            version: '1.0.0',
            types: [$articles, $people, $tags, $images, $videos],
            securitySchemes: ['bearer' => SecurityScheme::bearer('JWT')],
            defaultSecurity: [SecurityRequirement::scheme('bearer')],
        );
    }

    // ---- Related endpoints ------------------------------------------------------

    #[Test]
    public function aToOneRelationExposesBothRelatedAndRelationshipPaths(): void
    {
        $paths = $this->paths();

        self::assertArrayHasKey('/articles/{id}/author', $paths);
        self::assertArrayHasKey('/articles/{id}/relationships/author', $paths);
    }

    #[Test]
    public function aSuppressedRelatedEndpointEmitsNoRelatedPathButKeepsTheRelationshipPath(): void
    {
        $paths = $this->paths();

        // `cover` has exposesRelatedEndpoint() === false.
        self::assertArrayNotHasKey('/articles/{id}/cover', $paths);
        self::assertArrayHasKey('/articles/{id}/relationships/cover', $paths);
    }

    #[Test]
    public function theToOneRelatedDocumentDataIsNullableAndRefsTheRelatedResource(): void
    {
        $get = $this->arrAt($this->paths(), '/articles/{id}/author', 'get');
        $document = $this->strAt($get, 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref');
        self::assertSame('#/components/schemas/ArticlesAuthorRelatedDocument', $document);

        // The related-to-one document's `data` is nullable (an empty to-one is `data: null`),
        // unioning the related resource ref with the null type.
        $schemas = $this->arrAt($this->document()->toArray(), 'components', 'schemas');
        $data = $this->arrAt($schemas, 'ArticlesAuthorRelatedDocument', 'properties', 'data');
        $anyOf = $this->listAt($data, 'anyOf');
        self::assertSame('#/components/schemas/PeopleResource', $this->strAt($this->arrAt($anyOf, '0'), '$ref'));
        self::assertSame('null', $this->strAt($this->arrAt($anyOf, '1'), 'type'));
    }

    #[Test]
    public function aToManyRelatedCollectionRefsTheRelatedCollectionAndCarriesRelationScopedParameters(): void
    {
        $get = $this->arrAt($this->paths(), '/articles/{id}/tags', 'get');

        self::assertSame(
            '#/components/schemas/TagsCollection',
            $this->strAt($get, 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );

        // The related endpoint honours the MERGED vocabulary — the related `tags`
        // resource's own `filter[color]` AND the relation-scoped `filter[label]` — plus
        // the related-scoped include and the relation paginator.
        $names = $this->parameterNames($get);
        self::assertContains('filter[color]', $names);
        self::assertContains('filter[label]', $names);
        self::assertContains('sort', $names);
        self::assertContains('include', $names);
        self::assertContains('page[number]', $names);
        self::assertContains('page[size]', $names);

        // The `sort` enum unions the related resource's own (`id`/`-id`) and the
        // relation's (`label`/`-label`) sortable tokens.
        $sortTokens = $this->listAt($this->parameterNamed($get, 'sort'), 'schema', 'items', 'enum');
        self::assertContains('id', $sortTokens);
        self::assertContains('label', $sortTokens);
    }

    #[Test]
    public function aToOneRelatedAndRelationshipEndpointCarryTheRelatedFilterVocabulary(): void
    {
        // `GET /articles/{id}/author` and `…/relationships/author` honour a relation
        // filter that nulls the to-one when it excludes the target — so both project the
        // related `people` resource's `filter[name]` (but no sort/page on a to-one).
        $related = $this->arrAt($this->paths(), '/articles/{id}/author', 'get');
        self::assertContains('filter[name]', $this->parameterNames($related));
        self::assertNotContains('sort', $this->parameterNames($related));
        self::assertNotContains('page[number]', $this->parameterNames($related));

        $relationship = $this->arrAt($this->paths(), '/articles/{id}/relationships/author', 'get');
        self::assertContains('filter[name]', $this->parameterNames($relationship));

        // A to-MANY relationship (linkage) endpoint returns the whole linkage and takes
        // no query filters at all.
        $toManyRel = $this->arrAt($this->paths(), '/articles/{id}/relationships/tags', 'get');
        self::assertArrayNotHasKey('parameters', $toManyRel);
    }

    #[Test]
    public function aRelatedEndpointIncludeParameterEnumeratesTheRelatedTypesPathsNotTheParents(): void
    {
        // GET /articles/{id}/tags returns `tags` resources, so its `?include` is the
        // `tags` type's includable paths (`articles`) — NOT the article's (`author`/`tags`).
        $get = $this->arrAt($this->paths(), '/articles/{id}/tags', 'get');
        $include = $this->parameterNamed($get, 'include');

        self::assertSame('array', $this->strAt($include, 'schema', 'type'));
        self::assertSame(['articles'], $this->listAt($include, 'schema', 'items', 'enum'));
    }

    #[Test]
    public function aRelatedEndpointFieldsParametersAreScopedToTheRelatedReachableTypes(): void
    {
        // GET /articles/{id}/tags returns `tags` as primary data and may `?include=articles`,
        // so it advertises fields[tags] (the related type) and fields[articles] (reachable),
        // and NOT fields for unrelated types like people/images.
        $get = $this->arrAt($this->paths(), '/articles/{id}/tags', 'get');
        $names = $this->parameterNames($get);

        self::assertContains('fields[tags]', $names);
        self::assertContains('fields[articles]', $names);
        self::assertNotContains('fields[people]', $names);
        self::assertNotContains('fields[images]', $names);
    }

    #[Test]
    public function aPolymorphicToManyRelatedCollectionRefsAPerRelationCollectionUnioningEveryMemberResource(): void
    {
        // GET /articles/{id}/attachments returns a MIX of images|videos, so it must NOT
        // reuse a single member's collection (which would drop the other member's items).
        $get = $this->arrAt($this->paths(), '/articles/{id}/attachments', 'get');
        self::assertSame(
            '#/components/schemas/ArticlesAttachmentsRelatedCollection',
            $this->strAt($get, 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );

        // The per-relation collection's data.items is the anyOf of every member resource.
        $schemas = $this->arrAt($this->document()->toArray(), 'components', 'schemas');
        $items = $this->arrAt($schemas, 'ArticlesAttachmentsRelatedCollection', 'properties', 'data', 'items');
        $refs = [];
        foreach ($this->listAt($items, 'anyOf') as $member) {
            self::assertIsArray($member);
            $refs[] = $this->strAt($member, '$ref');
        }
        self::assertContains('#/components/schemas/ImagesResource', $refs);
        self::assertContains('#/components/schemas/VideosResource', $refs);
    }

    #[Test]
    public function aMonomorphicToManyRelatedCollectionStillRefsTheRelatedTypesPlainCollection(): void
    {
        // A single related type keeps reusing its own `<RelatedType>Collection` envelope.
        $get = $this->arrAt($this->paths(), '/articles/{id}/tags', 'get');
        self::assertSame(
            '#/components/schemas/TagsCollection',
            $this->strAt($get, 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    // ---- Relationship-linkage endpoints + mutation gating -----------------------

    #[Test]
    public function aToOneRelationshipEndpointHasGetAndPatchButNoPostOrDelete(): void
    {
        $relationship = $this->arrAt($this->paths(), '/articles/{id}/relationships/author');

        self::assertArrayHasKey('get', $relationship);
        self::assertArrayHasKey('patch', $relationship);
        self::assertArrayNotHasKey('post', $relationship);
        self::assertArrayNotHasKey('delete', $relationship);
    }

    #[Test]
    public function aToManyRelationshipEndpointHasEveryAllowedMutationVerb(): void
    {
        $relationship = $this->arrAt($this->paths(), '/articles/{id}/relationships/tags');

        self::assertArrayHasKey('get', $relationship);
        self::assertArrayHasKey('patch', $relationship);
        self::assertArrayHasKey('post', $relationship);
        self::assertArrayHasKey('delete', $relationship);
    }

    #[Test]
    public function aReadOnlyRelationshipEndpointHasGetOnly(): void
    {
        // `locked` is to-many but every mutation flag is false.
        $relationship = $this->arrAt($this->paths(), '/articles/{id}/relationships/locked');

        self::assertSame(['parameters', 'get'], \array_keys($relationship));
    }

    #[Test]
    public function theRelationshipEndpointsRefTheRelationshipDocumentEnvelope(): void
    {
        $relationship = $this->arrAt($this->paths(), '/articles/{id}/relationships/author');

        // GET reads the linkage document.
        self::assertSame(
            '#/components/schemas/ArticlesAuthorRelationshipDocument',
            $this->strAt($relationship, 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
        // PATCH bodies + echoes the same linkage document.
        self::assertSame(
            '#/components/schemas/ArticlesAuthorRelationshipDocument',
            $this->strAt($relationship, 'patch', 'requestBody', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function theRelationshipDocumentDataMatchesTheRelationArity(): void
    {
        $schemas = $this->arrAt($this->document()->toArray(), 'components', 'schemas');

        // To-one: nullable single identifier.
        $authorData = $this->arrAt($schemas, 'ArticlesAuthorRelationshipDocument', 'properties', 'data');
        self::assertSame('#/components/schemas/PeopleResourceIdentifier', $this->strAt($this->arrAt($this->listAt($authorData, 'anyOf'), '0'), '$ref'));

        // To-many: an array of identifiers.
        $tagsData = $this->arrAt($schemas, 'ArticlesTagsRelationshipDocument', 'properties', 'data');
        self::assertSame('array', $this->strAt($tagsData, 'type'));
        self::assertSame('#/components/schemas/TagsResourceIdentifier', $this->strAt($tagsData, 'items', '$ref'));
    }

    // ---- Custom-action endpoints ------------------------------------------------

    #[Test]
    public function aResourceScopedActionMountsUnderTheIdActionsSegmentWithASharedIdParameter(): void
    {
        $publish = $this->arrAt($this->paths(), '/articles/{id}/-actions/publish');

        self::assertArrayHasKey('post', $publish);
        self::assertSame('id', $this->strAt($publish, 'parameters', '0', 'name'));
        self::assertSame('path', $this->strAt($publish, 'parameters', '0', 'in'));
    }

    #[Test]
    public function aCollectionScopedActionMountsUnderTheCollectionActionsSegment(): void
    {
        $paths = $this->paths();

        self::assertArrayHasKey('/articles/-actions/import', $paths);
        self::assertArrayNotHasKey('/articles/{id}/-actions/import', $paths);
    }

    #[Test]
    public function aNoneInputActionCarriesNoRequestBodyAndAnOutputTypeReturnsItsDocument(): void
    {
        $post = $this->arrAt($this->paths(), '/articles/{id}/-actions/publish', 'post');

        self::assertArrayNotHasKey('requestBody', $post);
        self::assertSame(
            '#/components/schemas/ArticlesDocument',
            $this->strAt($post, 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function aRawInputActionCarriesAGenericBinaryBodyAndAnAbsentOutputTypeReturns204(): void
    {
        $post = $this->arrAt($this->paths(), '/articles/-actions/import', 'post');

        // Raw → a generic octet-stream binary body (not the JSON:API media type).
        $content = $this->arrAt($post, 'requestBody', 'content');
        self::assertArrayHasKey('application/octet-stream', $content);
        self::assertArrayNotHasKey('application/vnd.api+json', $content);
        self::assertSame('binary', $this->strAt($content, 'application/octet-stream', 'schema', 'format'));

        // No output type → 204.
        self::assertArrayHasKey('204', $this->arrAt($post, 'responses'));
        self::assertArrayNotHasKey('content', $this->arrAt($post, 'responses', '204'));
    }

    #[Test]
    public function aDocumentInputActionBodiesTheInputTypesCreateRequest(): void
    {
        $post = $this->arrAt($this->paths(), '/articles/{id}/-actions/draft', 'post');

        self::assertSame(
            '#/components/schemas/ArticlesCreateRequest',
            $this->strAt($post, 'requestBody', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function aSecuredActionCarriesTheConfiguredRequirementAnUnsecuredOneInherits(): void
    {
        $paths = $this->paths();

        // `publish` isSecured() → explicit per-operation security.
        $publish = $this->arrAt($paths, '/articles/{id}/-actions/publish', 'post');
        self::assertSame([['bearer' => []]], $this->at($publish, 'security'));

        // `import` is not secured → no per-operation security (inherits the doc default).
        $import = $this->arrAt($paths, '/articles/-actions/import', 'post');
        self::assertArrayNotHasKey('security', $import);
    }

    #[Test]
    public function aSecuredActionUnderAnEmptyDefaultEmitsNoSecurityKeyRatherThanAuthOptionalEmptyList(): void
    {
        // A secured action under a server with no document-level default requirement
        // must NOT emit `security: []` (which declares auth optional) — it omits the key
        // (inherits the empty default), mirroring the CRUD secured-op guard.
        $type = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make(), Str::make('title')],
            actions: [new FakeActionMetadata('publish', ['POST'], ActionScope::Resource, ActionInputMode::None, secured: true)],
        );
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]);
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        self::assertArrayNotHasKey('security', $this->arrAt($paths, '/articles/{id}/-actions/publish', 'post'));
    }

    #[Test]
    public function anActionCarriesItsOwnTagsAndSummary(): void
    {
        $publish = $this->arrAt($this->paths(), '/articles/{id}/-actions/publish', 'post');

        self::assertSame(['Articles'], $this->listAt($publish, 'tags'));
        self::assertSame('Publish the article', $this->strAt($publish, 'summary'));
    }

    // ---- Envelope refinements ---------------------------------------------------

    #[Test]
    public function thePrimarySingleDocumentDataIsANonNullableResourceRef(): void
    {
        $schemas = $this->arrAt($this->document()->toArray(), 'components', 'schemas');
        $data = $this->arrAt($schemas, 'ArticlesDocument', 'properties', 'data');

        // A direct $ref (no anyOf/null union) — a primary single fetch mandates a present resource.
        self::assertSame('#/components/schemas/ArticlesResource', $this->strAt($data, '$ref'));
        self::assertArrayNotHasKey('anyOf', $data);
    }

    // ---- Security on relationship/related endpoints ------------------------------

    #[Test]
    public function aRelationshipMutationMirrorsUpdateSecurityAndAReadInheritsTheDefault(): void
    {
        $relationship = $this->arrAt($this->paths(), '/articles/{id}/relationships/author');

        // Update is in securedOperations → the mutation carries explicit security.
        self::assertSame([['bearer' => []]], $this->at($relationship, 'patch', 'security'));

        // FetchOne is not secured → the linkage read inherits the doc default.
        self::assertArrayNotHasKey('security', $this->arrAt($relationship, 'get'));
    }

    // ---- Standalone type with only actions --------------------------------------

    #[Test]
    public function aTypeWithNoCrudButWithActionsStillContributesItsActionPaths(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'jobs',
            fields: [Id::make(), Str::make('name')],
            operations: [],
            actions: [new FakeActionMetadata('run', ['POST'], ActionScope::Collection, ActionInputMode::None)],
        );
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]);
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        self::assertArrayNotHasKey('/jobs', $paths);
        self::assertArrayNotHasKey('/jobs/{id}', $paths);
        self::assertArrayHasKey('/jobs/-actions/run', $paths);
    }

    // ---- Meta-validation + dangling refs ----------------------------------------

    #[Test]
    public function theFullDocumentValidatesAgainstTheOas31MetaSchema(): void
    {
        $document = $this->document();

        $result = $this->oasValidator()->validate($document->toJson(), self::OAS_SCHEMA_ID);

        self::assertTrue(
            $result->isValid(),
            'Projected stage-B document is not a valid OpenAPI 3.1 document: ' . $document->toJsonString(true),
        );
    }

    #[Test]
    public function theDocumentCarriesNoDanglingInternalSchemaReference(): void
    {
        $array = $this->document()->toArray();
        $components = $this->arrAt($array, 'components', 'schemas');

        $missing = [];
        foreach ($this->collectSchemaRefs($array) as $ref) {
            $name = \substr($ref, \strlen('#/components/schemas/'));
            if (!\array_key_exists($name, $components)) {
                $missing[$ref] = true;
            }
        }

        self::assertSame([], \array_keys($missing), 'Document carries dangling internal $ref(s): ' . \implode(', ', \array_keys($missing)));
    }

    #[Test]
    public function aSuppressedRelatedEndpointEmitsNoRelatedDocumentComponent(): void
    {
        // The `cover` to-one suppresses its related endpoint, so its related document is
        // NOT emitted (no path references it) — but its relationship document still
        // exists for the (exposed) linkage endpoint. Component emission tracks endpoint
        // exposure exactly, keeping the document dangling-ref-free.
        $schemas = $this->arrAt($this->document()->toArray(), 'components', 'schemas');

        self::assertArrayHasKey('ArticlesCoverRelationshipDocument', $schemas);
        self::assertArrayNotHasKey('ArticlesCoverRelatedDocument', $schemas);
    }

    // ---- helpers ----------------------------------------------------------------

    private function projector(): OpenApiProjector
    {
        return new OpenApiProjector();
    }

    private function document(): \haddowg\JsonApi\OpenApi\OpenApi
    {
        return $this->projector()->project($this->richServer());
    }

    /**
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        return $this->arrAt($this->document()->toArray(), 'paths');
    }

    /**
     * @param array<array-key, mixed> $operation
     * @return list<string>
     */
    private function parameterNames(array $operation): array
    {
        $names = [];
        foreach ($this->listAt($operation, 'parameters') as $parameter) {
            self::assertIsArray($parameter);
            self::assertArrayHasKey('name', $parameter);
            self::assertIsString($parameter['name']);
            $names[] = $parameter['name'];
        }

        return $names;
    }

    /**
     * @param array<array-key, mixed> $operation
     * @return array<array-key, mixed>
     */
    private function parameterNamed(array $operation, string $name): array
    {
        foreach ($this->listAt($operation, 'parameters') as $parameter) {
            self::assertIsArray($parameter);
            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail("Parameter {$name} not found");
    }

    /**
     * @param array<array-key, mixed> $node
     * @return list<string>
     */
    private function collectSchemaRefs(array $node): array
    {
        $refs = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && \is_string($value) && \str_starts_with($value, '#/components/schemas/')) {
                $refs[] = $value;

                continue;
            }
            if (\is_array($value)) {
                $refs = [...$refs, ...$this->collectSchemaRefs($value)];
            }
        }

        return $refs;
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function at(array $schema, string ...$keys): mixed
    {
        $cursor = $schema;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function arrAt(array $schema, string ...$keys): array
    {
        $value = $this->at($schema, ...$keys);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @return list<mixed>
     */
    private function listAt(array $schema, string ...$keys): array
    {
        return \array_values($this->arrAt($schema, ...$keys));
    }

    /**
     * @param array<array-key, mixed> $schema
     */
    private function strAt(array $schema, string ...$keys): string
    {
        $value = $this->at($schema, ...$keys);
        self::assertIsString($value);

        return $value;
    }

    /**
     * A validator with the vendored OAS 3.1 + 2020-12 meta-schema documents registered.
     */
    private function oasValidator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $register = function (string $base, array $documents) use ($resolver): void {
            foreach ($documents as $document) {
                $raw = \file_get_contents($base . $document);
                self::assertIsString($raw);
                $decoded = \json_decode($raw);
                self::assertInstanceOf(\stdClass::class, $decoded);
                $id = $decoded->{'$id'} ?? null;
                self::assertIsString($id);
                $resolver->registerRaw($decoded, $id);
            }
        };

        $register(__DIR__ . '/Fixture/meta-schema/', [
            'schema.json', 'meta/core.json', 'meta/applicator.json', 'meta/unevaluated.json',
            'meta/validation.json', 'meta/meta-data.json', 'meta/format-annotation.json', 'meta/content.json',
        ]);
        $register(__DIR__ . '/Fixture/oas-3.1/', ['schema.json', 'dialect.json', 'meta/base.json']);

        return $validator;
    }
}
