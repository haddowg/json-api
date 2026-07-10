<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\MediaType;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
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
                Str::make('title')->required()->describedAs('The article headline.')->example('Hello')->build(),
                Str::make('status')->enum(Status::class)->describedAs('Publication status.')->build(),
                Integer::make('wordCount')->nullable(),
            ],
            relations: [
                FakeRelationMetadata::toOne('author', ['people'], 'The author.'),
                FakeRelationMetadata::toMany('tags', ['tags']),
            ],
            includablePaths: ['author', 'tags'],
            uriType: 'articles',
            tags: ['Articles'],
            allowsClientId: false,
            description: 'A blog article.',
        );

        $people = FakeTypeMetadata::resource(
            type: 'people',
            fields: [
                Id::make(),
                Str::make('name')->required()->build(),
                Str::make('role')->enum(Status::class)->build(), // re-use the same enum → dedup
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
                Str::make('label')->required()->build(),
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

    /**
     * The same server as {@see server()} but with the Atomic Operations extension
     * enabled, mounted at `/operations`, tagged `Atomic Operations`, secured by the
     * bearer scheme.
     */
    private function serverWithAtomic(): FakeServerMetadata
    {
        $base = $this->server();

        return new FakeServerMetadata(
            title: 'Music API',
            version: '1.2.0',
            types: $base->types(),
            description: 'A JSON:API surface.',
            servers: [new Server('https://api.example.com', 'Production')],
            tags: [new Tag('Articles', 'Blog articles'), new Tag('People')],
            securitySchemes: ['bearer' => SecurityScheme::bearer('JWT')],
            defaultSecurity: [SecurityRequirement::scheme('bearer')],
            atomicOperations: new FakeAtomicOperationsMetadata(
                path: '/operations',
                tag: 'Atomic Operations',
                security: [SecurityRequirement::scheme('bearer')],
            ),
        );
    }

    #[Test]
    public function itTypesThePivotLinkageMetaForABelongsToManyRelation(): void
    {
        $playlists = FakeTypeMetadata::resource(
            type: 'playlists',
            fields: [Id::make(), Str::make('name')->required()->build()],
            relations: [
                FakeRelationMetadata::toMany('orderedTracks', ['tracks'], 'Tracks in order.', pivotFields: [
                    Integer::make('position')->describedAs('The track position.'),
                    Boolean::make('featured')->nullable(),
                ]),
                // A plain (non-pivot) to-many keeps a bare `$ref` linkage.
                FakeRelationMetadata::toMany('tags', ['tags']),
            ],
        );
        $tracks = FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make(), Str::make('title')->required()->build()]);
        $tags = FakeTypeMetadata::resource(type: 'tags', fields: [Id::make(), Str::make('label')->required()->build()]);

        $server = new FakeServerMetadata(title: 'Catalog', version: '1.0.0', types: [$playlists, $tracks, $tags]);
        $schemas = $this->arrAt($this->projector()->project($server)->toArray(), 'components', 'schemas');

        // The pivot relation's linkage identifier — in BOTH the embedded relationship
        // object and the relationship-document envelope — is an `allOf` of the base
        // identifier `$ref` plus a typed, OPTIONAL `meta.pivot`.
        foreach (['PlaylistsOrderedTracksRelationship', 'PlaylistsOrderedTracksRelationshipDocument'] as $component) {
            $identifier = $this->arrAt($schemas, $component, 'properties', 'data', 'items');
            self::assertArrayHasKey('allOf', $identifier, $component);

            $base = $this->arrAt($identifier, 'allOf', '0');
            self::assertSame('#/components/schemas/TracksResourceIdentifier', $base['$ref'] ?? null, $component);

            $pivot = $this->arrAt($identifier, 'allOf', '1', 'properties', 'meta', 'properties', 'pivot');
            self::assertSame('object', $pivot['type'] ?? null, $component);

            $position = $this->arrAt($pivot, 'properties', 'position');
            self::assertSame('integer', $position['type'] ?? null, $component);
            self::assertSame('The track position.', $position['description'] ?? null, $component);

            self::assertArrayHasKey('featured', $this->arrAt($pivot, 'properties'), $component);
            // Shared by the read response + the mutation request body, so nothing is required.
            self::assertArrayNotHasKey('required', $pivot, $component);
        }

        // A non-pivot to-many keeps the bare `$ref` linkage (no `allOf`, no meta typing).
        $tagsLinkage = $this->arrAt($schemas, 'PlaylistsTagsRelationship', 'properties', 'data', 'items');
        self::assertSame('#/components/schemas/TagsResourceIdentifier', $tagsLinkage['$ref'] ?? null);
        self::assertArrayNotHasKey('allOf', $tagsLinkage);
    }

    #[Test]
    public function itMarksReadOnlyPivotFieldsReadOnlyAndRequiredPivotFieldsRequired(): void
    {
        $playlists = FakeTypeMetadata::resource(
            type: 'playlists',
            fields: [Id::make(), Str::make('name')->required()->build()],
            relations: [
                FakeRelationMetadata::toMany('orderedTracks', ['tracks'], pivotFields: [
                    Integer::make('position')->required(),
                    Str::make('addedAt')->readOnly()->build(),
                ]),
            ],
        );
        $tracks = FakeTypeMetadata::resource(type: 'tracks', fields: [Id::make(), Str::make('title')->build()]);
        $server = new FakeServerMetadata(title: 'Catalog', version: '1.0.0', types: [$playlists, $tracks]);
        $schemas = $this->arrAt($this->projector()->project($server)->toArray(), 'components', 'schemas');

        $pivot = $this->arrAt(
            $schemas,
            'PlaylistsOrderedTracksRelationship',
            'properties',
            'data',
            'items',
            'allOf',
            '1',
            'properties',
            'meta',
            'properties',
            'pivot',
        );
        // A writable, create-required pivot field is required (a new member must carry it) — D18.
        self::assertSame(['position'], $this->listAt($pivot, 'required'));
        // A read-only pivot field is marked readOnly (a write client neither sends nor must supply it).
        self::assertTrue($this->arrAt($pivot, 'properties', 'addedAt')['readOnly'] ?? null);
        self::assertArrayNotHasKey('readOnly', $this->arrAt($pivot, 'properties', 'position'));
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
    public function documentEnvelopesOrderTopLevelMembersCanonically(): void
    {
        $schemas = $this->schemas();

        // The envelope schemas mirror the wire's canonical top-level member order
        // (TopLevelMembers::ORDER): data/errors first, jsonapi last.
        self::assertSame(
            ['data', 'included', 'links', 'meta', 'jsonapi'],
            \array_keys($this->arrAt($schemas, 'ArticlesDocument', 'properties')),
        );
        self::assertSame(
            ['data', 'included', 'links', 'meta', 'jsonapi'],
            \array_keys($this->arrAt($schemas, 'ArticlesCollection', 'properties')),
        );
        self::assertSame(
            ['errors', 'links', 'meta', 'jsonapi'],
            \array_keys($this->arrAt($schemas, 'ErrorDocument', 'properties')),
        );

        // A type with no includable relationship path never carries `included` (a
        // `?include` on it is rejected), so the member is omitted from its envelopes
        // rather than advertised as an always-empty array. `tags` declares no relations.
        self::assertSame(
            ['data', 'links', 'meta', 'jsonapi'],
            \array_keys($this->arrAt($schemas, 'TagsDocument', 'properties')),
        );
        self::assertSame(
            ['data', 'links', 'meta', 'jsonapi'],
            \array_keys($this->arrAt($schemas, 'TagsCollection', 'properties')),
        );
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
    public function theToManyRelationshipDocumentTypesItsPaginationLinks(): void
    {
        // A to-many relationship endpoint is a paginated linkage collection (ADR 0096),
        // so its document `links` is the typed `PaginationLinks`, not the permissive
        // `Links`; a to-one relationship does not paginate and keeps `Links`. Neither
        // describes a compound `included` (a relationship endpoint is linkage-only) — D16.
        $schemas = $this->schemas();

        self::assertSame(
            '#/components/schemas/PaginationLinks',
            $this->strAt($schemas, 'ArticlesTagsRelationshipDocument', 'properties', 'links', '$ref'),
        );
        self::assertSame(
            '#/components/schemas/Links',
            $this->strAt($schemas, 'ArticlesAuthorRelationshipDocument', 'properties', 'links', '$ref'),
        );
        self::assertArrayNotHasKey('included', $this->arrAt($schemas, 'ArticlesTagsRelationshipDocument', 'properties'));
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
    public function theRelationshipAndResourceObjectsRefTheSharedLinksAndMetaComponents(): void
    {
        $schemas = $this->schemas();

        // A relationship object's links carry the conventional self/related pair (not
        // pagination), so it $refs the shared `Links`; its meta $refs the shared `Meta`
        // (it may carry pivot / identifierMeta) — no more bare `{type: object}` (D22).
        self::assertSame('#/components/schemas/Links', $this->strAt($schemas, 'ArticlesAuthorRelationship', 'properties', 'links', '$ref'));
        self::assertSame('#/components/schemas/Meta', $this->strAt($schemas, 'ArticlesAuthorRelationship', 'properties', 'meta', '$ref'));

        // The resource object's links/meta likewise $ref the shared components, matching
        // the document-level links/meta.
        self::assertSame('#/components/schemas/Links', $this->strAt($schemas, 'ArticlesResource', 'properties', 'links', '$ref'));
        self::assertSame('#/components/schemas/Meta', $this->strAt($schemas, 'ArticlesResource', 'properties', 'meta', '$ref'));
    }

    #[Test]
    public function itDistinguishesCreateAndUpdateRequestSchemas(): void
    {
        $schemas = $this->schemas();

        // articles: client id FORBIDDEN → the create resource explicitly forbids `id`
        // (the `false` schema), so a client generated against the spec cannot send an
        // `id` the runtime would `403` — mirroring the atomic `add` schema.
        $create = $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data');
        self::assertFalse($this->at($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties', 'id'));
        self::assertSame(['type'], $this->listAt($create, 'required'));
        // the create body references the create-context attributes component, which
        // requires the declared-required field.
        self::assertSame('#/components/schemas/ArticlesCreateAttributes', $this->strAt($create, 'properties', 'attributes', '$ref'));
        self::assertContains('title', $this->listAt($schemas, 'ArticlesCreateAttributes', 'required'));

        // update resource requires `id`.
        $update = $this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data');
        self::assertArrayHasKey('id', $this->arrAt($update, 'properties'));
        self::assertSame(['type', 'id'], $this->listAt($update, 'required'));
        // update references the update-context attributes component, which carries no
        // `required` (a PATCH is partial — an absent member means "no change").
        self::assertSame('#/components/schemas/ArticlesUpdateAttributes', $this->strAt($update, 'properties', 'attributes', '$ref'));
        self::assertArrayNotHasKey('required', $this->arrAt($schemas, 'ArticlesUpdateAttributes'));

        // people ALLOWS (but does not require) a client id → the create resource exposes a
        // string `id`, but does not require it (the server assigns one when absent).
        $peopleCreate = $this->arrAt($schemas, 'PeopleCreateRequest', 'properties', 'data');
        self::assertSame('string', $this->strAt($peopleCreate, 'properties', 'id', 'type'));
        self::assertSame(['type'], $this->listAt($peopleCreate, 'required'));
    }

    #[Test]
    public function itTypesTheWriteRequestRelationshipsFromTheSettableRelations(): void
    {
        // A create may set any declared relation's initial linkage; an update replaces an
        // existing association, so it lists only the relations whose replacement is
        // permitted (an unconditionally locked relation is omitted) — D15.
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'articles',
                fields: [Id::make(), Str::make('title')->required()->build()],
                relations: [
                    FakeRelationMetadata::toOne('author', ['people']),
                    // A locked to-one: settable on create (initial state) but never replaced.
                    new FakeRelationMetadata('owner', ['people'], false, allowsReplace: false),
                ],
            )],
        );
        $schemas = $this->arrAt($this->projector()->project($server)->toArray(), 'components', 'schemas');

        // Create: both relations, each `$ref`-ing its relationship-object component.
        $create = $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');
        self::assertSame(['author', 'owner'], \array_keys($create));
        self::assertSame('#/components/schemas/ArticlesAuthorRelationship', $this->strAt($create, 'author', '$ref'));
        self::assertSame('#/components/schemas/ArticlesOwnerRelationship', $this->strAt($create, 'owner', '$ref'));

        // Update: only the replaceable relation.
        $update = $this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data', 'properties', 'relationships', 'properties');
        self::assertSame(['author'], \array_keys($update));
    }

    #[Test]
    public function itOmitsTheWriteRelationshipsPropertyWhenNoRelationIsSettable(): void
    {
        // An update where every relation is unconditionally locked emits no `relationships`
        // property at all (nothing is settable), while the create still lists it.
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'articles',
                fields: [Id::make(), Str::make('title')->required()->build()],
                relations: [new FakeRelationMetadata('owner', ['people'], false, allowsReplace: false)],
            )],
        );
        $schemas = $this->arrAt($this->projector()->project($server)->toArray(), 'components', 'schemas');

        self::assertArrayHasKey('relationships', $this->arrAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties'));
        self::assertArrayNotHasKey('relationships', $this->arrAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data', 'properties'));
    }

    #[Test]
    public function itMarksTheClientIdRequiredWhenThePolicyRequiresIt(): void
    {
        // A type whose id policy REQUIRES a client-supplied id: the create resource makes
        // `id` present AND required, so a client generated against the spec sends the `id`
        // a create-without-it would otherwise `403` on.
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'devices',
                fields: [Id::make(), Str::make('label')->required()->build()],
                allowsClientId: true,
                requiresClientId: true,
            )],
        );
        $schemas = $this->arrAt($this->projector()->project($server)->toArray(), 'components', 'schemas');

        $create = $this->arrAt($schemas, 'DevicesCreateRequest', 'properties', 'data');
        self::assertSame('string', $this->strAt($create, 'properties', 'id', 'type'));
        self::assertSame(['type', 'id'], $this->listAt($create, 'required'));
    }

    #[Test]
    public function itProjectsPerOperationSecurityOverridesAndInheritedAuthStatuses(): void
    {
        // A document-level default + a type that secures create, marks the single read
        // PUBLIC, and leaves the rest to inherit.
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(
                type: 'widgets',
                fields: [Id::make(), Str::make('name')->required()->build()],
                securedOperations: [OperationType::Create],
                publicOperations: [OperationType::FetchOne],
            )],
            securitySchemes: ['bearer' => SecurityScheme::bearer('JWT')],
            defaultSecurity: [SecurityRequirement::scheme('bearer')],
        );
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        // Secured op: a per-operation requirement + 401.
        $create = $this->arrAt($paths, '/widgets', 'post');
        self::assertNotSame([], $this->arrAt($create, 'security'));
        self::assertArrayHasKey('401', $this->arrAt($create, 'responses'));

        // PUBLIC op: an explicit `security: []` (overrides the document default) + NO 401.
        $read = $this->arrAt($paths, '/widgets/{id}', 'get');
        self::assertSame([], $this->arrAt($read, 'security'));
        self::assertArrayNotHasKey('401', $this->arrAt($read, 'responses'));

        // INHERIT op (the collection read): no per-operation `security` block, but 401 —
        // it inherits the document default (Tier 1).
        $list = $this->arrAt($paths, '/widgets', 'get');
        self::assertArrayNotHasKey('security', $list);
        self::assertArrayHasKey('401', $this->arrAt($list, 'responses'));
    }

    #[Test]
    public function itAddsNo401WhenNoSecurityIsDeclaredAtAll(): void
    {
        // No document default and no per-operation security: nothing advertises 401.
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(type: 'gadgets', fields: [Id::make(), Str::make('name')->build()])],
        );
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        self::assertArrayNotHasKey('401', $this->arrAt($paths, '/gadgets', 'get', 'responses'));
        self::assertArrayNotHasKey('401', $this->arrAt($paths, '/gadgets', 'post', 'responses'));
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
    public function theResourceObjectSchemaCarriesADescription(): void
    {
        $schemas = $this->schemas();

        // `articles` declared `description: 'A blog article.'` → surfaced verbatim.
        self::assertSame('A blog article.', $this->strAt($schemas, 'ArticlesResource', 'description'));

        // `tags` declared no description → the generated default naming the type.
        self::assertSame('An `tags` resource object.', $this->strAt($schemas, 'TagsResource', 'description'));

        // A standalone (fieldless) type's permissive resource object is described too.
        self::assertSame('An `healthcheck` resource object.', $this->strAt($schemas, 'HealthcheckResource', 'description'));
    }

    #[Test]
    public function everyCrudOperationCarriesAGeneratedDescription(): void
    {
        $paths = $this->arrAt($this->projector()->project($this->server())->toArray(), 'paths');

        self::assertSame(
            'Returns a paginated collection of `articles` resources.',
            $this->strAt($paths, '/articles', 'get', 'description'),
        );
        self::assertSame(
            'Returns a single `articles` resource by its `id`.',
            $this->strAt($paths, '/articles/{id}', 'get', 'description'),
        );
        self::assertSame(
            'Creates a new `articles` resource from the supplied attributes and relationships.',
            $this->strAt($paths, '/articles', 'post', 'description'),
        );
        self::assertSame(
            'Updates an existing `articles` resource, applying the supplied attributes and relationships.',
            $this->strAt($paths, '/articles/{id}', 'patch', 'description'),
        );
        self::assertSame(
            'Deletes the `articles` resource identified by its `id`.',
            $this->strAt($paths, '/articles/{id}', 'delete', 'description'),
        );
    }

    #[Test]
    public function aDeclaredOperationDescriptionOverridesTheGeneratedDefault(): void
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make(), Str::make('title')->required()->build()],
            operationDescriptions: [
                OperationType::FetchCollection->value => 'Browse the article catalogue.',
            ],
        );
        $server = new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$articles]);
        $paths = $this->arrAt($this->projector()->project($server)->toArray(), 'paths');

        // The override wins for the one operation it names …
        self::assertSame('Browse the article catalogue.', $this->strAt($paths, '/articles', 'get', 'description'));
        // … and the others still get their generated default.
        self::assertSame(
            'Returns a single `articles` resource by its `id`.',
            $this->strAt($paths, '/articles/{id}', 'get', 'description'),
        );
    }

    #[Test]
    public function relatedAndRelationshipOperationsCarryDescriptions(): void
    {
        $paths = $this->arrAt($this->projector()->project($this->server())->toArray(), 'paths');

        // `author` declared `'The author.'` → that one description applies to every
        // endpoint of the relationship (related + relationship + mutations).
        self::assertSame('The author.', $this->strAt($paths, '/articles/{id}/author', 'get', 'description'));
        self::assertSame('The author.', $this->strAt($paths, '/articles/{id}/relationships/author', 'get', 'description'));
        self::assertSame('The author.', $this->strAt($paths, '/articles/{id}/relationships/author', 'patch', 'description'));

        // `tags` declared no description → operation-specific generated defaults.
        self::assertSame(
            'Returns the related `tags` resources of a `articles`.',
            $this->strAt($paths, '/articles/{id}/tags', 'get', 'description'),
        );
        self::assertSame(
            'Returns the `tags` relationship linkage of a `articles`.',
            $this->strAt($paths, '/articles/{id}/relationships/tags', 'get', 'description'),
        );
        self::assertSame(
            'Fully replaces the `tags` relationship of a `articles` with the supplied linkage.',
            $this->strAt($paths, '/articles/{id}/relationships/tags', 'patch', 'description'),
        );
        self::assertSame(
            'Adds the supplied members to the `tags` relationship of a `articles`.',
            $this->strAt($paths, '/articles/{id}/relationships/tags', 'post', 'description'),
        );
        self::assertSame(
            'Removes the supplied members from the `tags` relationship of a `articles`.',
            $this->strAt($paths, '/articles/{id}/relationships/tags', 'delete', 'description'),
        );
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
            fields: [Id::make(), Str::make('title')->required()->build()],
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

    #[Test]
    public function itProjectsTheAtomicOperationsEndpointWhenEnabled(): void
    {
        $array = $this->projector()->project($this->serverWithAtomic())->toArray();
        $paths = $this->arrAt($array, 'paths');

        // The atomic POST is mounted at the configured path.
        self::assertArrayHasKey('/operations', $paths);
        $post = $this->arrAt($paths, '/operations', 'post');

        self::assertSame('atomic.operations', $this->strAt($post, 'operationId'));
        self::assertSame(['Atomic Operations'], $this->listAt($post, 'tags'));
        self::assertSame([['bearer' => []]], $this->at($post, 'security'));

        // Request + 200 response are carried under the extension-qualified media type.
        $extMediaType = MediaType::JSON_API . '; ext="' . AtomicExtension::URI . '"';
        self::assertSame(
            '#/components/schemas/AtomicOperationsRequest',
            $this->strAt($post, 'requestBody', 'content', $extMediaType, 'schema', '$ref'),
        );
        self::assertTrue($this->at($post, 'requestBody', 'required'));
        self::assertSame(
            '#/components/schemas/AtomicResultsResponse',
            $this->strAt($post, 'responses', '200', 'content', $extMediaType, 'schema', '$ref'),
        );

        // The enumerated error responses each reference the shared error document. The atomic op
        // is secured (bearer default + per-endpoint), so it carries 401 like every other
        // operation — the effective-security invariant core #99 established (D17).
        foreach (['400', '401', '403', '404', '406', '409', '415', '422', '500'] as $status) {
            self::assertSame(
                '#/components/schemas/ErrorDocument',
                $this->strAt($post, 'responses', $status, 'content', MediaType::JSON_API, 'schema', '$ref'),
                "missing/incorrect error response {$status}",
            );
        }
    }

    #[Test]
    public function theAtomicOperationOmits401WhenNoEffectiveSecurityApplies(): void
    {
        // No document default and no per-endpoint security → the atomic op is unsecured, so it
        // carries no 401 (mirroring the CRUD effective-security rule).
        $base = $this->server();
        $server = new FakeServerMetadata(
            title: 'Public API',
            version: '1.0.0',
            types: $base->types(),
            atomicOperations: new FakeAtomicOperationsMetadata(path: '/operations', tag: 'Atomic Operations'),
        );
        $post = $this->arrAt($this->projector()->project($server)->toArray(), 'paths', '/operations', 'post');

        self::assertArrayNotHasKey('401', $this->arrAt($post, 'responses'));
        self::assertArrayHasKey('403', $this->arrAt($post, 'responses'));
    }

    #[Test]
    #[Group('spec:atomic-operations')]
    public function aReadOnlyTypeEmitsNoWriteOrAtomicWriteComponents(): void
    {
        // A type whose allow-list exposes only reads must emit no write components
        // (CreateRequest/UpdateRequest and their attributes) and no atomic add/update
        // shapes, and the atomic `data` union must not reference them (D26).
        $readOnly = FakeTypeMetadata::resource(
            type: 'reports',
            fields: [Id::make(), Str::make('title')->build()],
            operations: [OperationType::FetchCollection, OperationType::FetchOne],
        );
        $writable = FakeTypeMetadata::resource(type: 'notes', fields: [Id::make(), Str::make('body')->build()]);
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$readOnly, $writable],
            atomicOperations: new FakeAtomicOperationsMetadata(path: '/operations', tag: 'Atomic Operations'),
        );
        $document = $this->projector()->project($server)->toArray();
        $schemas = $this->arrAt($document, 'components', 'schemas');

        // The read type keeps its read-side components.
        self::assertArrayHasKey('ReportsAttributes', $schemas);
        self::assertArrayHasKey('ReportsResource', $schemas);
        // …but none of the write / atomic-write ones.
        foreach ([
            'ReportsCreateAttributes', 'ReportsUpdateAttributes',
            'ReportsCreateRequest', 'ReportsUpdateRequest',
            'ReportsAtomicAdd', 'ReportsAtomicUpdate',
        ] as $absent) {
            self::assertArrayNotHasKey($absent, $schemas, "read-only type must not emit {$absent}");
        }

        // The writable type keeps the full write + atomic-write set.
        foreach ([
            'NotesCreateRequest', 'NotesUpdateRequest',
            'NotesAtomicAdd', 'NotesAtomicUpdate',
        ] as $present) {
            self::assertArrayHasKey($present, $schemas, "writable type must emit {$present}");
        }

        // The atomic operation `data` union references only the writable type's shapes.
        $refs = $this->refsIn($this->listAt($schemas, 'AtomicOperation', 'properties', 'data', 'anyOf'));
        self::assertContains('#/components/schemas/NotesAtomicAdd', $refs);
        self::assertContains('#/components/schemas/NotesAtomicUpdate', $refs);
        self::assertNotContains('#/components/schemas/ReportsAtomicAdd', $refs);
        self::assertNotContains('#/components/schemas/ReportsAtomicUpdate', $refs);

        // And nothing dangles as a result of the gating.
        $this->assertNoDanglingSchemaRefs($document);
    }

    #[Test]
    public function itEmitsTheAtomicComponentsReferencingTheResourceSchemas(): void
    {
        $schemas = $this->arrAt(
            $this->projector()->project($this->serverWithAtomic())->toArray(),
            'components',
            'schemas',
        );

        foreach (['AtomicOperationsRequest', 'AtomicOperation', 'AtomicResultsResponse', 'AtomicResult'] as $component) {
            self::assertArrayHasKey($component, $schemas, "missing atomic component {$component}");
        }

        // The request document requires the `atomic:operations` array (minItems 1) of
        // AtomicOperation.
        self::assertSame([AtomicExtension::OPERATIONS_MEMBER], $this->listAt($schemas, 'AtomicOperationsRequest', 'required'));
        $operations = $this->arrAt($schemas, 'AtomicOperationsRequest', 'properties', AtomicExtension::OPERATIONS_MEMBER);
        self::assertSame('array', $this->strAt($operations, 'type'));
        self::assertSame(1, $this->at($operations, 'minItems'));
        self::assertSame('#/components/schemas/AtomicOperation', $this->strAt($operations, 'items', '$ref'));

        // The operation object: op enum + ref/href/data; op required.
        self::assertSame(['add', 'update', 'remove'], $this->listAt($schemas, 'AtomicOperation', 'properties', 'op', 'enum'));
        self::assertContains('op', $this->listAt($schemas, 'AtomicOperation', 'required'));
        self::assertSame(['type'], $this->listAt($schemas, 'AtomicOperation', 'properties', 'ref', 'required'));

        // The operation `data` anyOf references each type's discrete `add` and
        // `update` resource components (write-shaped, not the id-requiring read shape).
        $operationData = $this->listAt($schemas, 'AtomicOperation', 'properties', 'data', 'anyOf');
        $refs = $this->refsIn($operationData);
        self::assertContains('#/components/schemas/ArticlesAtomicAdd', $refs);
        self::assertContains('#/components/schemas/ArticlesAtomicUpdate', $refs);
        self::assertContains('#/components/schemas/PeopleAtomicAdd', $refs);
        self::assertContains('#/components/schemas/PeopleAtomicUpdate', $refs);
        self::assertNotContains('#/components/schemas/ArticlesResource', $refs);

        // `articles` forbids a client id, so its **add** forbids `id` (a `false` schema,
        // server-assigned) and needs no id/lid choice; `lid` stays optional.
        self::assertSame(['type'], $this->listAt($schemas, 'ArticlesAtomicAdd', 'required'));
        self::assertFalse($this->at($schemas, 'ArticlesAtomicAdd', 'properties', 'id'));
        self::assertArrayHasKey('lid', $this->arrAt($schemas, 'ArticlesAtomicAdd', 'properties'));
        self::assertArrayNotHasKey('oneOf', $this->arrAt($schemas, 'ArticlesAtomicAdd'));
        // Its `relationships` are typed from the settable relations (D15), each `$ref`-ing
        // the relationship-object component, not a bare `{type: object}`.
        self::assertSame(
            ['author', 'tags'],
            \array_keys($this->arrAt($schemas, 'ArticlesAtomicAdd', 'properties', 'relationships', 'properties')),
        );

        // `people` allows a client id, so its **add** offers `id` and a titled three-mode
        // `oneOf` (client id / local id / server-assigned).
        self::assertSame('string', $this->strAt($schemas, 'PeopleAtomicAdd', 'properties', 'id', 'type'));
        self::assertSame(
            ['Client-supplied id', 'Local id (lid)', 'Server-assigned id'],
            \array_map(fn(mixed $m): mixed => \is_array($m) ? ($m['title'] ?? null) : null, $this->listAt($schemas, 'PeopleAtomicAdd', 'oneOf')),
        );

        // An **update** is partial (update-context attributes, no `required`) and
        // identifies the target by id / lid / ref-or-href — a titled three-mode `oneOf`.
        self::assertSame('#/components/schemas/ArticlesUpdateAttributes', $this->strAt($schemas, 'ArticlesAtomicUpdate', 'properties', 'attributes', '$ref'));
        self::assertSame(
            ['By id', 'By local id (lid)', 'Targeted by ref/href'],
            \array_map(fn(mixed $m): mixed => \is_array($m) ? ($m['title'] ?? null) : null, $this->listAt($schemas, 'ArticlesAtomicUpdate', 'oneOf')),
        );

        // The results document requires the `atomic:results` array of AtomicResult.
        self::assertSame([AtomicExtension::RESULTS_MEMBER], $this->listAt($schemas, 'AtomicResultsResponse', 'required'));
        self::assertSame(
            '#/components/schemas/AtomicResult',
            $this->strAt($schemas, 'AtomicResultsResponse', 'properties', AtomicExtension::RESULTS_MEMBER, 'items', '$ref'),
        );

        // An AtomicResult is an object with optional data/meta — no `required` member,
        // so an empty `{}` validates. Its `data` anyOf references the resource schemas.
        self::assertArrayNotHasKey('required', $this->arrAt($schemas, 'AtomicResult'));
        $resultData = $this->listAt($schemas, 'AtomicResult', 'properties', 'data', 'anyOf');
        self::assertContains('#/components/schemas/ArticlesResource', $this->refsIn($resultData));
    }

    /**
     * The projected `AtomicOperation.data` schema must accept the real atomic-write
     * wire shapes — a no-id create (the only valid create body for a type with
     * `allowsClientId=false`, like the example app's `playlists`), a local-id (`lid`)
     * create, and a single to-one relationship identifier referenced by `lid` — and
     * still reject a body with no `type`. This is the inverse of the probe that
     * surfaced the original defect (the read-shape `<Type>Resource` required `id`, so
     * every id-less write was wrongly rejected).
     */
    #[Test]
    #[Group('spec:document-structure')]
    public function itValidatesRealAtomicWriteWireShapes(): void
    {
        $schemas = $this->arrAt(
            $this->projector()->project($this->serverWithAtomic())->toArray(),
            'components',
            'schemas',
        );

        $validator = $this->json2020Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $doc = \json_decode((string) \json_encode(['components' => ['schemas' => $schemas]]));
        self::assertInstanceOf(\stdClass::class, $doc);
        $resolver->registerRaw($doc, 'urn:atomic-doc');

        $dataSchema = \json_decode((string) \json_encode(['$ref' => 'urn:atomic-doc#/components/schemas/AtomicOperation/properties/data']));
        self::assertInstanceOf(\stdClass::class, $dataSchema);

        // The instance is decoded to its JSON value (object/array/null) before
        // validation; opis validates the data argument as-is (it only decodes a
        // string *schema*, never the data).
        $isValid = function (mixed $instance) use ($validator, $dataSchema): bool {
            return $validator->validate(\json_decode((string) \json_encode($instance)), $dataSchema)->isValid();
        };

        // The headline cases the read-shape schema wrongly rejected.
        self::assertTrue($isValid(['type' => 'articles', 'attributes' => ['title' => 'x']]), 'create (no id)');
        self::assertTrue($isValid(['type' => 'articles', 'lid' => 'a1', 'attributes' => ['title' => 'x']]), 'create (lid)');
        self::assertTrue($isValid(['type' => 'articles', 'lid' => 'a1']), 'to-one identifier (lid)');
        // Already-accepted shapes still validate.
        self::assertTrue($isValid(['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'x']]), 'update (id)');
        self::assertTrue($isValid(['type' => 'articles', 'id' => '1']), 'to-one identifier (id)');
        self::assertTrue($isValid([['type' => 'articles', 'lid' => 'a1']]), 'to-many array');
        self::assertTrue($isValid(null), 'to-one cleared (null)');

        // A body with no `type` is still rejected (the union has no all-permissive arm).
        self::assertFalse($isValid(['attributes' => ['title' => 'x']]), 'a type-less operation data must be rejected');

        // `id` and `lid` are mutually exclusive (the oneOf rejects a body carrying both).
        self::assertFalse($isValid(['type' => 'articles', 'id' => '1', 'lid' => 'a1']), 'id and lid together must be rejected');
    }

    #[Test]
    public function itSharesContextCorrectAttributeComponentsAcrossSchemas(): void
    {
        // Three context-correct attributes components, each referenced where it belongs:
        // read by the resource object; create by the create request + atomic add; update
        // by the update request + atomic update.
        $schemas = $this->arrAt(
            $this->projector()->project($this->serverWithAtomic())->toArray(),
            'components',
            'schemas',
        );

        self::assertSame('#/components/schemas/ArticlesAttributes', $this->strAt($schemas, 'ArticlesResource', 'properties', 'attributes', '$ref'));
        self::assertSame('#/components/schemas/ArticlesCreateAttributes', $this->strAt($schemas, 'ArticlesCreateRequest', 'properties', 'data', 'properties', 'attributes', '$ref'));
        self::assertSame('#/components/schemas/ArticlesCreateAttributes', $this->strAt($schemas, 'ArticlesAtomicAdd', 'properties', 'attributes', '$ref'));
        self::assertSame('#/components/schemas/ArticlesUpdateAttributes', $this->strAt($schemas, 'ArticlesUpdateRequest', 'properties', 'data', 'properties', 'attributes', '$ref'));
        self::assertSame('#/components/schemas/ArticlesUpdateAttributes', $this->strAt($schemas, 'ArticlesAtomicUpdate', 'properties', 'attributes', '$ref'));

        // The read component lists its guaranteed-present members in required[] (every
        // read attribute here is unconditionally present); the create component lists the
        // create-required members; an update is partial, so it carries no required[].
        self::assertSame(['title', 'status', 'wordCount'], $this->listAt($schemas, 'ArticlesAttributes', 'required'));
        self::assertArrayNotHasKey('required', $this->arrAt($schemas, 'ArticlesUpdateAttributes'));
        self::assertContains('title', $this->listAt($schemas, 'ArticlesCreateAttributes', 'required'));
    }

    #[Test]
    public function itDefinesTheAtomicTagAtTheDocumentRoot(): void
    {
        $array = $this->projector()->project($this->serverWithAtomic())->toArray();

        $tagNames = [];
        foreach ($this->listAt($array, 'tags') as $tag) {
            self::assertIsArray($tag);
            self::assertArrayHasKey('name', $tag);
            $tagNames[] = $tag['name'];
        }

        self::assertContains('Atomic Operations', $tagNames);
    }

    #[Test]
    public function itOmitsTheAtomicEndpointAndComponentsWhenNotEnabled(): void
    {
        // The plain server() does not enable the extension.
        $array = $this->projector()->project($this->server())->toArray();

        self::assertArrayNotHasKey('/operations', $this->arrAt($array, 'paths'));

        $schemas = $this->arrAt($array, 'components', 'schemas');
        foreach (['AtomicOperationsRequest', 'AtomicOperation', 'AtomicResultsResponse', 'AtomicResult'] as $component) {
            self::assertArrayNotHasKey($component, $schemas, "unexpected atomic component {$component}");
        }

        $tagNames = [];
        foreach ($this->listAt($array, 'tags') as $tag) {
            self::assertIsArray($tag);
            $tagNames[] = $tag['name'] ?? null;
        }
        self::assertNotContains('Atomic Operations', $tagNames);
    }

    #[Test]
    public function theAtomicEnabledDocumentValidatesAndCarriesNoDanglingReference(): void
    {
        $document = $this->projector()->project($this->serverWithAtomic());

        $result = $this->oasValidator()->validate($document->toJson(), self::OAS_SCHEMA_ID);
        self::assertTrue(
            $result->isValid(),
            'Atomic-enabled document is not a valid OpenAPI 3.1 document: ' . $document->toJsonString(true),
        );

        $this->assertNoDanglingSchemaRefs($document->toArray());
    }

    /**
     * Collects the `$ref` strings present at the top level of each member of an
     * `anyOf` list (a member may be a `$ref` node or an inline schema).
     *
     * @param list<mixed> $members
     * @return list<string>
     */
    private function refsIn(array $members): array
    {
        $refs = [];
        foreach ($members as $member) {
            if (\is_array($member) && isset($member['$ref']) && \is_string($member['$ref'])) {
                $refs[] = $member['$ref'];
            }
        }

        return $refs;
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
