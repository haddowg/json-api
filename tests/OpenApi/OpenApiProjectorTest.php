<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drives the {@see OpenApiProjector} from in-core {@see FakeServerMetadata} fixtures
 * (no Symfony): asserts the skeleton + component set + envelopes are well-formed,
 * that a backed-enum type yields exactly one `$ref`'d named component (§4.8), and
 * that the assembled (path-less) document validates against the vendored OAS 3.1
 * meta-schema (the spec §10 acceptance criterion).
 */
#[CoversClass(OpenApiProjector::class)]
#[Group('spec:document-structure')]
final class OpenApiProjectorTest extends TestCase
{
    private const OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    private function projector(): OpenApiProjector
    {
        return new OpenApiProjector();
    }

    /**
     * A two-resource + one-standalone server: `articles` (a backed-enum `status`
     * attribute + an `author` to-one and `tags` to-many), `people`, and a polymorphic
     * `comments` to-one (`author` → person|article), plus a standalone serializer
     * type with no field inventory.
     */
    private function server(): FakeServerMetadata
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [
                Id::make(),
                Str::make('title')->required()->description('The article headline.')->example('Hello'),
                Str::make('status')->enum(Status::class)->description('Publication status.'),
                Integer::make('wordCount')->nullable(),
            ],
            relations: [
                FakeRelationMetadata::toOne('author', ['people'], 'The author.'),
                FakeRelationMetadata::toMany('tags', ['tags']),
            ],
            uriType: 'articles',
            tags: ['Articles'],
            allowsClientId: false,
            description: 'A blog article.',
        );

        $people = FakeTypeMetadata::resource(
            type: 'people',
            fields: [
                Id::make(),
                Str::make('name')->required(),
                Str::make('role')->enum(Status::class), // re-use the same enum → dedup
                Boolean::make('active'),
            ],
            relations: [
                // A polymorphic to-one: linkage is a oneOf of member identifiers.
                FakeRelationMetadata::toOne('featured', ['articles', 'people'], 'A featured resource.'),
            ],
            tags: ['People'],
            allowsClientId: true,
        );

        $tags = FakeTypeMetadata::resource(
            type: 'tags',
            fields: [
                Id::make(),
                Str::make('label')->required(),
            ],
            tags: ['Tags'],
        );

        return new FakeServerMetadata(
            title: 'Music API',
            version: '1.2.0',
            types: [$articles, $people, $tags, FakeTypeMetadata::standalone('healthcheck')],
            description: 'A JSON:API surface.',
            contact: new Contact('Greg', 'https://example.com', 'g@example.com'),
            license: new License('MIT', identifier: 'MIT'),
            servers: [new Server('https://api.example.com', 'Production')],
            tags: [new Tag('Articles', 'Blog articles'), new Tag('People')],
            securitySchemes: ['bearer' => SecurityScheme::bearer('JWT')],
            defaultSecurity: [SecurityRequirement::scheme('bearer')],
        );
    }

    #[Test]
    public function itProjectsTheSkeleton(): void
    {
        $array = $this->projector()->project($this->server())->toArray();

        self::assertSame('3.1.0', $array['openapi']);
        self::assertSame('Music API', $this->strAt($array, 'info', 'title'));
        self::assertSame('1.2.0', $this->strAt($array, 'info', 'version'));
        self::assertSame('g@example.com', $this->strAt($array, 'info', 'contact', 'email'));
        self::assertSame('MIT', $this->strAt($array, 'info', 'license', 'name'));
        self::assertSame([['url' => 'https://api.example.com', 'description' => 'Production']], $this->at($array, 'servers'));
        self::assertSame([['bearer' => []]], $this->at($array, 'security'));
        self::assertSame('Articles', $this->strAt($array, 'tags', '0', 'name'));

        // Slice 3 (stage A) now projects CRUD paths from the operation allow-list.
        self::assertArrayHasKey('paths', $array);
        self::assertArrayHasKey('/articles', $this->arrAt($array, 'paths'));
        self::assertArrayHasKey('/articles/{id}', $this->arrAt($array, 'paths'));

        self::assertSame('http', $this->strAt($array, 'components', 'securitySchemes', 'bearer', 'type'));
    }

    #[Test]
    public function itEmitsTheSharedAndPerTypeComponents(): void
    {
        $schemas = $this->schemas();

        // Shared.
        foreach (['JsonApi', 'Meta', 'Links', 'PaginationLinks', 'Error', 'ErrorSource', 'ErrorDocument'] as $shared) {
            self::assertArrayHasKey($shared, $schemas, "missing shared component {$shared}");
        }

        // Per-type (articles).
        foreach ([
            'ArticlesAttributes', 'ArticlesResource', 'ArticlesResourceIdentifier',
            'ArticlesCreateRequest', 'ArticlesUpdateRequest', 'ArticlesDocument', 'ArticlesCollection',
            'ArticlesAuthorRelationship', 'ArticlesTagsRelationship',
        ] as $component) {
            self::assertArrayHasKey($component, $schemas, "missing component {$component}");
        }

        // The error document references the shared error object.
        self::assertSame('#/components/schemas/Error', $this->strAt($schemas, 'ErrorDocument', 'properties', 'errors', 'items', '$ref'));
        self::assertContains('errors', $this->listAt($schemas, 'ErrorDocument', 'required'));
    }

    #[Test]
    public function itGivesAStandaloneTypeAPermissiveResourceObjectWithoutAttributesOrRequests(): void
    {
        $schemas = $this->schemas();

        self::assertArrayHasKey('HealthcheckResource', $schemas);
        // No declared field inventory → no Attributes / write-request components.
        self::assertArrayNotHasKey('HealthcheckAttributes', $schemas);
        self::assertArrayNotHasKey('HealthcheckCreateRequest', $schemas);

        self::assertSame('healthcheck', $this->strAt($schemas, 'HealthcheckResource', 'properties', 'type', 'const'));
        // A response resource object requires both `type` and `id` (JSON:API §7.2).
        self::assertSame(['type', 'id'], $this->listAt($schemas, 'HealthcheckResource', 'required'));
    }

    #[Test]
    public function itHoistsABackedEnumToOneNamedComponentReferencedFromEveryUsage(): void
    {
        $schemas = $this->schemas();

        // Exactly one Status component, regardless of being used by two types.
        self::assertArrayHasKey('Status', $schemas);
        self::assertSame(['draft', 'published', 'archived'], $this->listAt($schemas, 'Status', 'enum'));
        self::assertSame(['Draft', 'Published', 'Archived'], $this->listAt($schemas, 'Status', 'x-enum-varnames'));
        // The described cases surface a markdown table in the component description.
        self::assertStringContainsString('| Value | Description |', $this->strAt($schemas, 'Status', 'description'));

        // Both usages are a $ref to the one component — no inline enum repeated.
        $articleStatus = $this->arrAt($schemas, 'ArticlesAttributes', 'properties', 'status');
        self::assertSame('#/components/schemas/Status', $articleStatus['$ref']);
        self::assertArrayNotHasKey('enum', $articleStatus);

        self::assertSame('#/components/schemas/Status', $this->strAt($schemas, 'PeopleAttributes', 'properties', 'role', '$ref'));
    }

    #[Test]
    public function itProjectsToOneToManyAndPolymorphicLinkage(): void
    {
        $schemas = $this->schemas();

        // To-one: nullable single identifier ref.
        self::assertSame('The author.', $this->strAt($schemas, 'ArticlesAuthorRelationship', 'description'));
        $authorData = $this->arrAt($schemas, 'ArticlesAuthorRelationship', 'properties', 'data');
        self::assertArrayHasKey('anyOf', $authorData);
        self::assertSame('#/components/schemas/PeopleResourceIdentifier', $this->strAt($authorData, 'anyOf', '0', '$ref'));
        self::assertSame(['type' => 'null'], $this->at($authorData, 'anyOf', '1'));

        // To-many: an array of identifiers.
        self::assertSame('array', $this->strAt($schemas, 'ArticlesTagsRelationship', 'properties', 'data', 'type'));

        // Polymorphic to-one: an anyOf of member identifiers (then nullable).
        $featured = $this->arrAt($schemas, 'PeopleFeaturedRelationship', 'properties', 'data');
        self::assertArrayHasKey('anyOf', $featured);
        // anyOf[0] is the polymorphic identifier union; anyOf[1] is the null branch.
        self::assertSame('#/components/schemas/ArticlesResourceIdentifier', $this->strAt($featured, 'anyOf', '0', 'anyOf', '0', '$ref'));
        self::assertSame('#/components/schemas/PeopleResourceIdentifier', $this->strAt($featured, 'anyOf', '0', 'anyOf', '1', '$ref'));
    }

    #[Test]
    public function itDistinguishesCreateAndUpdateRequestSchemas(): void
    {
        $schemas = $this->schemas();

        // articles: no client id → create resource has no `id` property.
        $create = $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data');
        self::assertArrayNotHasKey('id', $this->arrAt($create, 'properties'));
        self::assertSame(['type'], $this->listAt($create, 'required'));
        // the create attributes require the declared-required field.
        self::assertContains('title', $this->listAt($create, 'properties', 'attributes', 'required'));

        // update resource requires `id`.
        $update = $this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data');
        self::assertArrayHasKey('id', $this->arrAt($update, 'properties'));
        self::assertSame(['type', 'id'], $this->listAt($update, 'required'));
        // update attributes carry no `required` (an absent member means "no change").
        self::assertArrayNotHasKey('required', $this->arrAt($update, 'properties', 'attributes'));

        // people allows a client id → create resource exposes `id`.
        $peopleCreate = $this->arrAt($schemas, 'PeopleCreateRequest', 'properties', 'data');
        self::assertArrayHasKey('id', $this->arrAt($peopleCreate, 'properties'));
    }

    #[Test]
    public function theResourceObjectWiresRelationshipsToTheirComponents(): void
    {
        $schemas = $this->schemas();

        $relationships = $this->arrAt($schemas, 'ArticlesResource', 'properties', 'relationships', 'properties');
        self::assertSame('#/components/schemas/ArticlesAuthorRelationship', $this->strAt($relationships, 'author', '$ref'));
        self::assertSame('#/components/schemas/ArticlesTagsRelationship', $this->strAt($relationships, 'tags', '$ref'));
    }

    #[Test]
    public function theAssembledDocumentValidatesAgainstTheOas31MetaSchema(): void
    {
        $document = $this->projector()->project($this->server());

        $result = $this->oasValidator()->validate($document->toJson(), self::OAS_SCHEMA_ID);

        self::assertTrue(
            $result->isValid(),
            'Projected document is not a valid OpenAPI 3.1 document: ' . $document->toJsonString(true),
        );
    }

    #[Test]
    public function everyComponentSchemaIsAValidJsonSchema2020(): void
    {
        $document = $this->projector()->project($this->server());
        $validator = $this->json2020Validator();

        foreach ($document->components->schemas as $name => $schema) {
            $result = $validator->validate($schema->toJson(), 'https://json-schema.org/draft/2020-12/schema');
            self::assertTrue(
                $result->isValid(),
                "Component {$name} is not a valid JSON Schema 2020-12 document: " . \json_encode($schema->toArray(), \JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * The OAS meta-schema treats Schema Objects as opaque and never descends into
     * their internal `$ref`s, so it cannot catch a dangling local reference. Assert
     * directly that every `#/components/schemas/<X>` reference resolves to a present
     * component (a real consumer — Swagger UI / codegen — would break otherwise).
     */
    #[Test]
    public function theDocumentCarriesNoDanglingInternalSchemaReference(): void
    {
        $this->assertNoDanglingSchemaRefs($this->projector()->project($this->server())->toArray());
    }

    /**
     * A related type referenced by a relation but **not** registered as a server
     * type still resolves: the projector synthesizes a minimal
     * `<RelatedType>ResourceIdentifier` so its linkage `$ref` is never dangling.
     */
    #[Test]
    public function itSynthesizesAnIdentifierForAnUnregisteredRelatedType(): void
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make(), Str::make('title')->required()],
            // `categories` is a related type but is never registered on the server.
            relations: [FakeRelationMetadata::toOne('category', ['categories'])],
        );

        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$articles]);
        $array = $this->projector()->project($server)->toArray();
        $schemas = $this->arrAt($array, 'components', 'schemas');

        self::assertArrayHasKey('CategoriesResourceIdentifier', $schemas);
        self::assertSame('categories', $this->strAt($schemas, 'CategoriesResourceIdentifier', 'properties', 'type', 'const'));
        self::assertSame(['type', 'id'], $this->listAt($schemas, 'CategoriesResourceIdentifier', 'required'));
        self::assertSame(
            '#/components/schemas/CategoriesResourceIdentifier',
            $this->strAt($schemas, 'ArticlesCategoryRelationship', 'properties', 'data', 'anyOf', '0', '$ref'),
        );

        // The synthesized identifier is exactly what keeps the unregistered-related
        // case free of a dangling reference (the scenario the meta-schema cannot catch).
        $this->assertNoDanglingSchemaRefs($array);
    }

    /**
     * Asserts every `#/components/schemas/<X>` reference anywhere in `$array`
     * resolves to a present component.
     *
     * @param array<array-key, mixed> $array
     */
    private function assertNoDanglingSchemaRefs(array $array): void
    {
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

    /**
     * Recursively collects every `#/components/schemas/<X>` reference value anywhere
     * in the document graph.
     *
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
     * The projected document's component schemas as nested arrays (assertion-friendly).
     *
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        $array = $this->projector()->project($this->server())->toArray();

        return $this->arrAt($array, 'components', 'schemas');
    }

    /**
     * Walks a nested array by key path, narrowing at each step (the
     * {@see SchemaProjectorTest} idiom — keeps PHPStan L9 happy over mixed graphs).
     *
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
     * Like {@see at()} but asserts (and types) the leaf as an array.
     *
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
     * Like {@see at()} but asserts (and types) the leaf as a list.
     *
     * @param array<array-key, mixed> $schema
     * @return list<mixed>
     */
    private function listAt(array $schema, string ...$keys): array
    {
        $value = $this->arrAt($schema, ...$keys);

        return \array_values($value);
    }

    /**
     * Like {@see at()} but asserts (and types) the leaf as a string.
     *
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
     * registered by their canonical `$id` (mirrors {@see OpenApiMetaValidationTest}).
     */
    private function oasValidator(): Validator
    {
        $validator = $this->json2020Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $base = __DIR__ . '/Fixture/oas-3.1/';
        foreach (['schema.json', 'dialect.json', 'meta/base.json'] as $document) {
            $raw = \file_get_contents($base . $document);
            self::assertIsString($raw);
            $decoded = \json_decode($raw);
            self::assertInstanceOf(\stdClass::class, $decoded);
            $id = $decoded->{'$id'} ?? null;
            self::assertIsString($id);
            $resolver->registerRaw($decoded, $id);
        }

        return $validator;
    }

    /**
     * A validator with the vendored JSON Schema 2020-12 meta-schema registered.
     */
    private function json2020Validator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $base = __DIR__ . '/Fixture/meta-schema/';
        $documents = [
            'schema.json', 'meta/core.json', 'meta/applicator.json', 'meta/unevaluated.json',
            'meta/validation.json', 'meta/meta-data.json', 'meta/format-annotation.json', 'meta/content.json',
        ];
        foreach ($documents as $document) {
            $raw = \file_get_contents($base . $document);
            self::assertIsString($raw);
            $decoded = \json_decode($raw);
            self::assertInstanceOf(\stdClass::class, $decoded);
            $id = $decoded->{'$id'} ?? null;
            self::assertIsString($id);
            $resolver->registerRaw($decoded, $id);
        }

        return $validator;
    }
}
