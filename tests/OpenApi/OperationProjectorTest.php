<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives the **stage-A** path/operation projection (CRUD paths + enumerated query
 * parameters + standard error responses + tags + per-operation security) from
 * in-core {@see FakeServerMetadata} fixtures (no Symfony), and meta-validates the
 * now-path-bearing document against the vendored OAS 3.1 meta-schema (spec §10).
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[Group('spec:document-structure')]
final class OperationProjectorTest extends TestCase
{
    private const OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    /**
     * A single rich `articles` resource: every CRUD operation, two filters (one
     * constrained, one presence-only), a sort, a page paginator, two includable
     * paths, and `create`/`update` marked secured.
     */
    private function richServer(): FakeServerMetadata
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [
                Id::make(),
                Str::make('title')->required(),
                Integer::make('wordCount')->nullable(),
            ],
            relations: [
                FakeRelationMetadata::toOne('author', ['people']),
                FakeRelationMetadata::toMany('tags', ['tags']),
            ],
            tags: ['Articles'],
            securedOperations: [OperationType::Create, OperationType::Update],
            paginatorKind: PaginatorKind::Page,
            filters: [
                Where::make('status')->describedAs('Filter by status.'),
                Where::make('wordCount')->integer(),
                Range::make('rating'),
                DateRange::make('published'),
            ],
            sorts: [SortByField::make('title'), SortByField::make('wordCount')],
            includablePaths: ['author', 'tags', 'author.company'],
        );

        return new FakeServerMetadata(
            title: 'Blog API',
            version: '1.0.0',
            types: [$articles],
            servers: [],
            securitySchemes: ['bearer' => SecurityScheme::bearer('JWT')],
            defaultSecurity: [SecurityRequirement::scheme('bearer')],
        );
    }

    #[Test]
    public function itProjectsTheCollectionPathWithGetAndPost(): void
    {
        $collection = $this->arrAt($this->paths(), '/articles');

        self::assertArrayHasKey('get', $collection);
        self::assertArrayHasKey('post', $collection);
    }

    #[Test]
    public function itProjectsTheResourcePathWithGetPatchDeleteAndASharedIdParameter(): void
    {
        $resource = $this->arrAt($this->paths(), '/articles/{id}');

        self::assertArrayHasKey('get', $resource);
        self::assertArrayHasKey('patch', $resource);
        self::assertArrayHasKey('delete', $resource);

        // The {id} path parameter is shared at the path-item level (every method).
        self::assertSame('id', $this->strAt($resource, 'parameters', '0', 'name'));
        self::assertSame('path', $this->strAt($resource, 'parameters', '0', 'in'));
        self::assertTrue($this->at($resource, 'parameters', '0', 'required'));
    }

    #[Test]
    public function theCollectionGetEnumeratesEveryQueryParameter(): void
    {
        $names = $this->parameterNames($this->arrAt($this->paths(), '/articles', 'get'));

        // One filter[] per declared filter.
        self::assertContains('filter[status]', $names);
        self::assertContains('filter[wordCount]', $names);
        // sort / include / fields[type] / page[number] + page[size].
        self::assertContains('sort', $names);
        self::assertContains('include', $names);
        self::assertContains('fields[articles]', $names);
        self::assertContains('page[number]', $names);
        self::assertContains('page[size]', $names);
    }

    #[Test]
    public function aConstrainedFilterParameterCarriesItsValueSchema(): void
    {
        $get = $this->arrAt($this->paths(), '/articles', 'get');
        $wordCount = $this->parameterNamed($get, 'filter[wordCount]');

        // The integer() builder declares a Pattern → emitted as the value schema.
        self::assertSame('^-?[0-9]+$', $this->strAt($wordCount, 'schema', 'pattern'));

        // A filter's own declared description is surfaced (not the generic fallback).
        $status = $this->parameterNamed($get, 'filter[status]');
        self::assertSame('Filter by status.', $this->strAt($status, 'description'));
    }

    #[Test]
    public function aFilterWithNoDeclaredDescriptionFallsBackToTheGenericLabel(): void
    {
        $get = $this->arrAt($this->paths(), '/articles', 'get');

        // wordCount is a bare integer() Where with no describedAs() — generic fallback.
        $wordCount = $this->parameterNamed($get, 'filter[wordCount]');
        self::assertSame('Filter the collection by `wordCount`.', $this->strAt($wordCount, 'description'));
    }

    #[Test]
    public function aRangeFilterParameterIsADeepObjectAndCarriesItsStrategyDescription(): void
    {
        $get = $this->arrAt($this->paths(), '/articles', 'get');

        // A structured Range renders as a deepObject parameter (ADR 0077) and surfaces
        // the strategy's preset description.
        $rating = $this->parameterNamed($get, 'filter[rating]');
        self::assertSame('deepObject', $this->strAt($rating, 'style'));
        self::assertTrue($this->at($rating, 'explode'));
        self::assertSame(
            'Matches values within the given inclusive numeric range (min/max, either optional).',
            $this->strAt($rating, 'description'),
        );

        $published = $this->parameterNamed($get, 'filter[published]');
        self::assertSame('deepObject', $this->strAt($published, 'style'));
        self::assertTrue($this->at($published, 'explode'));

        // A scalar filter carries no style/explode.
        $wordCount = $this->parameterNamed($get, 'filter[wordCount]');
        self::assertArrayNotHasKey('style', $wordCount);
        self::assertArrayNotHasKey('explode', $wordCount);
    }

    #[Test]
    public function aRangeFilterParameterCarriesAnObjectValueSchemaWithMinAndMaxBounds(): void
    {
        $get = $this->arrAt($this->paths(), '/articles', 'get');

        // Range projects an object value schema (ADR 0076), not the scalar numeric
        // string its per-bound constraint would otherwise yield: the nested
        // filter[rating][min]/[max] wire shape is documented as {min, max}.
        $rating = $this->parameterNamed($get, 'filter[rating]');
        self::assertSame('object', $this->strAt($rating, 'schema', 'type'));
        self::assertSame('^-?[0-9]+(?:\.[0-9]+)?$', $this->strAt($rating, 'schema', 'properties', 'min', 'pattern'));
        self::assertSame('^-?[0-9]+(?:\.[0-9]+)?$', $this->strAt($rating, 'schema', 'properties', 'max', 'pattern'));
    }

    #[Test]
    public function aDateRangeFilterParameterCarriesDateTimeBoundsInItsObjectValueSchema(): void
    {
        $get = $this->arrAt($this->paths(), '/articles', 'get');

        // DateRange bounds are string/date-time (spec §6).
        $published = $this->parameterNamed($get, 'filter[published]');
        self::assertSame('object', $this->strAt($published, 'schema', 'type'));
        self::assertSame('string', $this->strAt($published, 'schema', 'properties', 'min', 'type'));
        self::assertSame('date-time', $this->strAt($published, 'schema', 'properties', 'min', 'format'));
        self::assertSame('string', $this->strAt($published, 'schema', 'properties', 'max', 'type'));
        self::assertSame('date-time', $this->strAt($published, 'schema', 'properties', 'max', 'format'));
    }

    #[Test]
    public function theSortParameterIsACommaDelimitedListEnumeratingAscAndDescTokens(): void
    {
        $sort = $this->parameterNamed($this->arrAt($this->paths(), '/articles', 'get'), 'sort');

        // A single parameter carrying a comma-separated list: OAS form/explode:false
        // over an array schema whose items enumerate the allowed tokens.
        self::assertSame('form', $this->strAt($sort, 'style'));
        self::assertFalse($this->at($sort, 'explode'));
        self::assertSame('array', $this->strAt($sort, 'schema', 'type'));
        self::assertSame(
            ['title', '-title', 'wordCount', '-wordCount'],
            $this->listAt($sort, 'schema', 'items', 'enum'),
        );
    }

    #[Test]
    public function theIncludeParameterIsACommaDelimitedListEnumeratingTheIncludablePaths(): void
    {
        $include = $this->parameterNamed($this->arrAt($this->paths(), '/articles', 'get'), 'include');

        self::assertSame('form', $this->strAt($include, 'style'));
        self::assertFalse($this->at($include, 'explode'));
        self::assertSame('array', $this->strAt($include, 'schema', 'type'));
        self::assertSame(
            ['author', 'tags', 'author.company'],
            $this->listAt($include, 'schema', 'items', 'enum'),
        );
    }

    #[Test]
    public function theFetchOneGetCarriesIncludeAndFieldsButNoCollectionParameters(): void
    {
        $names = $this->parameterNames($this->arrAt($this->paths(), '/articles/{id}', 'get'));

        self::assertContains('include', $names);
        self::assertContains('fields[articles]', $names);
        self::assertNotContains('sort', $names);
        self::assertNotContains('page[number]', $names);
        self::assertNotContains('filter[status]', $names);
    }

    #[Test]
    public function pageParametersFollowThePaginatorKind(): void
    {
        // Each paginator kind's `page[…]` parameters are exercised via the FetchCollection
        // path it lands on — one type per kind in a single server, projected together.
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'pages',
                    fields: [Id::make(), Str::make('name')],
                    operations: [OperationType::FetchCollection],
                    paginatorKind: PaginatorKind::Page,
                ),
                FakeTypeMetadata::resource(
                    type: 'offsets',
                    fields: [Id::make(), Str::make('name')],
                    operations: [OperationType::FetchCollection],
                    paginatorKind: PaginatorKind::Offset,
                ),
                FakeTypeMetadata::resource(
                    type: 'cursors',
                    fields: [Id::make(), Str::make('name')],
                    operations: [OperationType::FetchCollection],
                    paginatorKind: PaginatorKind::Cursor,
                ),
                FakeTypeMetadata::resource(
                    type: 'unpaged',
                    fields: [Id::make(), Str::make('name')],
                    operations: [OperationType::FetchCollection],
                    paginatorKind: PaginatorKind::None,
                ),
            ],
        );
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        self::assertSame(
            ['page[number]', 'page[size]'],
            $this->pageParameterNames($this->arrAt($paths, '/pages', 'get')),
        );
        self::assertSame(
            ['page[offset]', 'page[limit]'],
            $this->pageParameterNames($this->arrAt($paths, '/offsets', 'get')),
        );
        self::assertSame(
            ['page[cursor]', 'page[size]'],
            $this->pageParameterNames($this->arrAt($paths, '/cursors', 'get')),
        );
        self::assertSame([], $this->pageParameterNames($this->arrAt($paths, '/unpaged', 'get')));
    }

    #[Test]
    public function theCreateOperationReferencesTheCreateRequestAndReturns201WithALocationHeader(): void
    {
        $post = $this->arrAt($this->paths(), '/articles', 'post');

        self::assertSame(
            '#/components/schemas/ArticlesCreateRequest',
            $this->strAt($post, 'requestBody', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );

        self::assertSame(
            '#/components/schemas/ArticlesDocument',
            $this->strAt($post, 'responses', '201', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
        // The 201 advertises the Location header.
        self::assertArrayHasKey('Location', $this->arrAt($post, 'responses', '201', 'headers'));
    }

    #[Test]
    public function theUpdateReferencesTheUpdateRequestAndReturns200(): void
    {
        $patch = $this->arrAt($this->paths(), '/articles/{id}', 'patch');

        self::assertSame(
            '#/components/schemas/ArticlesUpdateRequest',
            $this->strAt($patch, 'requestBody', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/ArticlesDocument',
            $this->strAt($patch, 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function theDeleteReturns204NoContent(): void
    {
        $delete = $this->arrAt($this->paths(), '/articles/{id}', 'delete');

        self::assertArrayHasKey('204', $this->arrAt($delete, 'responses'));
        self::assertArrayNotHasKey('content', $this->arrAt($delete, 'responses', '204'));
    }

    #[Test]
    public function everyOperationCarriesTheTypesTagsAndCrudErrorsRefTheSharedErrorDocument(): void
    {
        $paths = $this->paths();

        foreach ([['/articles', 'get'], ['/articles', 'post'], ['/articles/{id}', 'patch']] as [$path, $method]) {
            $operation = $this->arrAt($paths, $path, $method);
            self::assertSame(['Articles'], $this->listAt($operation, 'tags'), "{$method} {$path} should be tagged Articles");
        }

        // The create's 422 references the shared error document.
        $post = $this->arrAt($paths, '/articles', 'post');
        self::assertSame(
            '#/components/schemas/ErrorDocument',
            $this->strAt($post, 'responses', '422', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function aSecuredOperationCarriesTheConfiguredRequirementAndAnUnsecuredOneInheritsTheDefault(): void
    {
        $paths = $this->paths();

        // create is in securedOperations → explicit per-operation security.
        $post = $this->arrAt($paths, '/articles', 'post');
        self::assertSame([['bearer' => []]], $this->at($post, 'security'));

        // fetchCollection is NOT secured → no per-operation security (inherits the doc default).
        $get = $this->arrAt($paths, '/articles', 'get');
        self::assertArrayNotHasKey('security', $get);
    }

    #[Test]
    public function aSecuredOperationUnderAnEmptyDefaultEmitsNoSecurityKeyRatherThanAuthOptionalEmptyList(): void
    {
        // A type marks Create secured, but the server has no document-level default
        // requirement (defaultSecurity() === []). Emitting `security: []` would actively
        // declare auth OPTIONAL for the op (OAS 3.1) — the inverse of the secured intent.
        // The op must instead emit NO per-operation `security` (inherit the empty default).
        $type = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make(), Str::make('title')],
            securedOperations: [OperationType::Create],
        );
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]);
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        self::assertArrayNotHasKey('security', $this->arrAt($paths, '/articles', 'post'));
    }

    #[Test]
    public function theOperationAllowListGatesWhichEndpointsExist(): void
    {
        // A read-only type: only fetchCollection + fetchOne.
        $type = FakeTypeMetadata::resource(
            type: 'reports',
            fields: [Id::make(), Str::make('name')],
            operations: [OperationType::FetchCollection, OperationType::FetchOne],
        );
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]);
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        self::assertArrayHasKey('get', $this->arrAt($paths, '/reports'));
        self::assertArrayNotHasKey('post', $this->arrAt($paths, '/reports'));
        self::assertArrayHasKey('get', $this->arrAt($paths, '/reports/{id}'));
        self::assertArrayNotHasKey('patch', $this->arrAt($paths, '/reports/{id}'));
        self::assertArrayNotHasKey('delete', $this->arrAt($paths, '/reports/{id}'));
    }

    #[Test]
    public function aStandaloneTypeWithNoOperationsContributesNoPaths(): void
    {
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::standalone('healthcheck')],
        );
        $array = $this->projector()->project($server)->toArray();

        // No allowed operations → no paths at all (the document is path-less again,
        // valid because components are always emitted).
        self::assertArrayNotHasKey('paths', $array);
    }

    #[Test]
    public function aTypeMountedUnderADistinctUriTypeUsesItInThePathTemplate(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'blog-posts',
            fields: [Id::make(), Str::make('title')],
            uriType: 'posts',
            operations: [OperationType::FetchCollection],
        );
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]);
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        // Path uses uriType; component refs use the (PascalCased) type.
        self::assertArrayHasKey('/posts', $paths);
        self::assertSame(
            '#/components/schemas/BlogPostsCollection',
            $this->strAt($paths, '/posts', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function theFullPathBearingDocumentValidatesAgainstTheOas31MetaSchema(): void
    {
        $document = $this->projector()->project($this->richServer());

        $result = $this->oasValidator()->validate($document->toJson(), self::OAS_SCHEMA_ID);

        self::assertTrue(
            $result->isValid(),
            'Projected path-bearing document is not a valid OpenAPI 3.1 document: ' . $document->toJsonString(true),
        );

        // Sanity: it really does carry paths now.
        self::assertArrayHasKey('paths', $document->toArray());
    }

    #[Test]
    public function theDocumentCarriesNoDanglingInternalSchemaReference(): void
    {
        $array = $this->projector()->project($this->richServer())->toArray();
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

    // ---- helpers ----------------------------------------------------------------

    private function projector(): OpenApiProjector
    {
        return new OpenApiProjector();
    }

    /**
     * The projected document's `paths` as nested arrays (assertion-friendly).
     *
     * @return array<string, mixed>
     */
    private function paths(): array
    {
        return $this->arrAt($this->projector()->project($this->richServer())->toArray(), 'paths');
    }

    /**
     * The `name`s of an operation's parameters.
     *
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
     * The operation's `page[…]` parameter names, in declared order (the others filtered
     * out) — so a per-paginator-kind assertion reads off the projected path.
     *
     * @param array<array-key, mixed> $operation
     * @return list<string>
     */
    private function pageParameterNames(array $operation): array
    {
        return \array_values(\array_filter(
            $this->parameterNames($operation),
            static fn(string $name): bool => \str_starts_with($name, 'page['),
        ));
    }

    /**
     * Returns the named parameter of an operation.
     *
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
     * A validator with the vendored OAS 3.1 + 2020-12 meta-schema documents
     * registered (mirrors {@see OpenApiProjectorTest}).
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
