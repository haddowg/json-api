<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAPI projection is **registration-aware** (ADR 0131): a profile-gated
 * affordance is advertised only when the server registered the profile that makes
 * the runtime honour it, and the `jsonapi` object's `profile`/`ext` enums reflect
 * the registered profile/extension set. This drives the four registration-gated
 * seams — `?withCount` (Countable), `relatedQuery` (Relationship Queries), the
 * cursor page-schema `x-profile` marker, and the `jsonapi` object enums — from
 * in-core {@see FakeServerMetadata} fixtures that register (or don't) each profile.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
final class RegistrationAwareProjectionTest extends TestCase
{
    // ---- withCount (Countable profile) ------------------------------------------

    #[Test]
    public function withCountIsAdvertisedOnlyWhenTheCountableProfileIsRegistered(): void
    {
        // A countable type: `collectionWithCountTokens` yields `_self_`.
        $type = FakeTypeMetadata::resource(
            type: 'books',
            fields: [Id::make()->build(), Str::make('title')->build()],
            operations: [OperationType::FetchCollection],
            countable: true,
        );

        // Unregistered → the runtime never honours `?withCount`, so no parameter.
        $unregistered = $this->paths(new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]));
        self::assertNotContains('withCount', $this->parameterNames($this->arrAt($unregistered, '/books', 'get')));

        // Registered → the countable endpoint advertises `?withCount` with its `_self_` token.
        $registered = $this->paths(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$type],
            profiles: [CountableProfile::URI],
        ));
        $withCount = $this->parameterNamed($this->arrAt($registered, '/books', 'get'), 'withCount');
        self::assertContains('_self_', $this->listAt($withCount, 'schema', 'items', 'enum'));
        self::assertSame(CountableProfile::URI, $withCount['x-profile'] ?? null);
    }

    #[Test]
    public function withCountIsAbsentForANonCountableTypeEvenWhenTheProfileIsRegistered(): void
    {
        // Nothing countable → no valid token → no parameter, registered or not.
        $type = FakeTypeMetadata::resource(
            type: 'plain',
            fields: [Id::make()->build(), Str::make('name')->build()],
            operations: [OperationType::FetchCollection],
        );

        $paths = $this->paths(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$type],
            profiles: [CountableProfile::URI],
        ));
        self::assertNotContains('withCount', $this->parameterNames($this->arrAt($paths, '/plain', 'get')));
    }

    // ---- relatedQuery (Relationship Queries profile) ----------------------------

    #[Test]
    public function relatedQueryReferencesTheSingleComponentOnlyWhenRegisteredAndTheTypeHasRelations(): void
    {
        // Unregistered → no `relatedQuery` on either primary read endpoint, and the shared
        // component is not emitted.
        $unregistered = $this->projector()->project($this->articlesServer([]))->toArray();
        $unregisteredPaths = $this->arrAt($unregistered, 'paths');
        self::assertNotContains('#/components/parameters/relatedQuery', $this->parameterRefs($this->arrAt($unregisteredPaths, '/articles', 'get')));
        self::assertNotContains('#/components/parameters/relatedQuery', $this->parameterRefs($this->arrAt($unregisteredPaths, '/articles/{id}', 'get')));
        self::assertArrayNotHasKey('parameters', $this->arrAt($unregistered, 'components'));

        // Registered → both the collection and resource read endpoints `$ref` the SAME
        // single component, defined once under `#/components/parameters/relatedQuery`.
        $registered = $this->projector()->project($this->articlesServer([RelationshipQueriesProfile::URI]))->toArray();
        $registeredPaths = $this->arrAt($registered, 'paths');
        self::assertContains('#/components/parameters/relatedQuery', $this->parameterRefs($this->arrAt($registeredPaths, '/articles', 'get')));
        self::assertContains('#/components/parameters/relatedQuery', $this->parameterRefs($this->arrAt($registeredPaths, '/articles/{id}', 'get')));

        // The component itself: a `deepObject` query parameter named `relatedQuery`,
        // self-describing its profile, whose generic shape is an object keyed by
        // relationship path — NOT an enumeration of the type's relations.
        $component = $this->arrAt($registered, 'components', 'parameters', 'relatedQuery');
        self::assertSame('relatedQuery', $component['name'] ?? null);
        self::assertSame('query', $component['in'] ?? null);
        self::assertSame('deepObject', $component['style'] ?? null);
        self::assertSame(RelationshipQueriesProfile::URI, $component['x-profile'] ?? null);
        self::assertSame('object', $this->strAt($component, 'schema', 'type'));
        $perPath = $this->arrAt($component, 'schema', 'additionalProperties');
        self::assertSame(['sort', 'filter'], \array_keys($this->arrAt($perPath, 'properties')));
        self::assertFalse($perPath['additionalProperties'] ?? null);
    }

    #[Test]
    public function relatedQueryComponentIsOmittedWhenNoRegisteredTypeHasRelations(): void
    {
        // Profile registered, but the only type declares no relation to address → nothing
        // references the component, so it is not emitted (mirrors the shared MetaDocument).
        $document = $this->projector()->project(new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(type: 'tags', fields: [Id::make()->build(), Str::make('label')->build()])],
            profiles: [RelationshipQueriesProfile::URI],
        ))->toArray();

        self::assertArrayNotHasKey('parameters', $this->arrAt($document, 'components'));
        self::assertNotContains('#/components/parameters/relatedQuery', $this->parameterRefs($this->arrAt($document, 'paths', '/tags', 'get')));
    }

    // ---- Cursor page-schema x-profile marker ------------------------------------

    #[Test]
    public function theCursorPageMarkerIsCarriedWhenRegisteredAndStrippedWhenNot(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'feed',
            fields: [Id::make()->build(), Str::make('body')->build()],
            operations: [OperationType::FetchCollection],
            pageSchema: CursorPaginator::make()->describePageSchema(),
        );

        // Not registered → the statically-emitted marker is stripped, but the cursor page
        // parameter itself stays (cursor pagination works without the profile registered).
        $unregistered = $this->parameterNamed(
            $this->arrAt($this->paths(new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type])), '/feed', 'get'),
            'page',
        );
        self::assertArrayNotHasKey('x-profile', $this->arrAt($unregistered, 'schema'));
        self::assertSame(['after', 'before', 'size'], \array_keys($this->arrAt($unregistered, 'schema', 'properties')));

        // Registered → the page schema carries the cursor profile marker.
        $registered = $this->parameterNamed(
            $this->arrAt($this->paths(new FakeServerMetadata(
                title: 'API',
                version: '1.0.0',
                types: [$type],
                profiles: [CursorPaginationProfile::URI],
            )), '/feed', 'get'),
            'page',
        );
        self::assertSame(CursorPaginationProfile::URI, $this->arrAt($registered, 'schema')['x-profile'] ?? null);
    }

    #[Test]
    public function theCursorBranchMarkerInAMenuIsStrippedButTheBranchAlwaysStays(): void
    {
        $menu = MultiPaginator::make(PagePaginator::make(), CursorPaginator::make())->default('page');
        $type = FakeTypeMetadata::resource(
            type: 'things',
            fields: [Id::make()->build(), Str::make('name')->build()],
            operations: [OperationType::FetchCollection],
            pageSchema: $menu->describePageSchema(),
        );

        // Not registered: the cursor branch is present (its keys intact) but carries no
        // `x-profile`; the page branch never had one.
        $unregistered = $this->parameterNamed(
            $this->arrAt($this->paths(new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type])), '/things', 'get'),
            'page',
        );
        $branches = $this->listAt($unregistered, 'schema', 'oneOf');
        self::assertCount(2, $branches);
        $cursorBranch = $this->arrAt($branches, '1');
        self::assertSame(['after', 'before', 'size', 'kind'], \array_keys($this->arrAt($cursorBranch, 'properties')));
        self::assertArrayNotHasKey('x-profile', $cursorBranch);

        // Registered: the cursor branch carries the marker; the page branch still does not.
        $registered = $this->parameterNamed(
            $this->arrAt($this->paths(new FakeServerMetadata(
                title: 'API',
                version: '1.0.0',
                types: [$type],
                profiles: [CursorPaginationProfile::URI],
            )), '/things', 'get'),
            'page',
        );
        $registeredBranches = $this->listAt($registered, 'schema', 'oneOf');
        self::assertArrayNotHasKey('x-profile', $this->arrAt($registeredBranches, '0'));
        self::assertSame(CursorPaginationProfile::URI, $this->arrAt($registeredBranches, '1')['x-profile'] ?? null);
    }

    // ---- jsonapi object profile / ext enums -------------------------------------

    #[Test]
    public function theJsonApiObjectKeepsOpenArraysWhenNoProfileOrExtensionIsAdvertised(): void
    {
        $schemas = $this->arrAt(
            $this->projector()->project($this->bareServer([]))->toArray(),
            'components',
            'schemas',
        );

        // Empty set → the open `array<uri-string>` shape (no enum) for both members.
        $profileItems = $this->arrAt($schemas, 'JsonApi', 'properties', 'profile', 'items');
        self::assertArrayNotHasKey('enum', $profileItems);
        self::assertSame('uri', $profileItems['format'] ?? null);

        $extItems = $this->arrAt($schemas, 'JsonApi', 'properties', 'ext', 'items');
        self::assertArrayNotHasKey('enum', $extItems);
        self::assertSame('uri', $extItems['format'] ?? null);
    }

    #[Test]
    public function theJsonApiObjectProfileEnumEnumeratesTheRegisteredProfiles(): void
    {
        $schemas = $this->arrAt(
            $this->projector()->project($this->bareServer([CountableProfile::URI, RelationshipQueriesProfile::URI]))->toArray(),
            'components',
            'schemas',
        );

        self::assertSame(
            [CountableProfile::URI, RelationshipQueriesProfile::URI],
            $this->listAt($schemas, 'JsonApi', 'properties', 'profile', 'items', 'enum'),
        );
        // No extension advertised → `ext` stays an open array.
        self::assertArrayNotHasKey('enum', $this->arrAt($schemas, 'JsonApi', 'properties', 'ext', 'items'));
    }

    #[Test]
    public function theJsonApiObjectExtEnumIsDerivedFromTheAtomicExtensionMetadata(): void
    {
        $schemas = $this->arrAt(
            $this->projector()->project(new FakeServerMetadata(
                title: 'API',
                version: '1.0.0',
                types: [FakeTypeMetadata::resource(type: 'notes', fields: [Id::make()->build(), Str::make('body')->build()])],
                atomicOperations: new FakeAtomicOperationsMetadata(),
            ))->toArray(),
            'components',
            'schemas',
        );

        self::assertSame(
            [AtomicExtension::URI],
            $this->listAt($schemas, 'JsonApi', 'properties', 'ext', 'items', 'enum'),
        );
        // No profile registered → `profile` stays an open array.
        self::assertArrayNotHasKey('enum', $this->arrAt($schemas, 'JsonApi', 'properties', 'profile', 'items'));
    }

    // ---- Helpers ----------------------------------------------------------------

    /**
     * A two-type server whose `articles` type declares a relation to address; the given
     * profiles are registered.
     *
     * @param list<string> $profiles
     */
    private function articlesServer(array $profiles): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'articles',
                    fields: [Id::make()->build(), Str::make('title')->build()],
                    relations: [new FakeRelationMetadata('author', ['people'], false)],
                    includablePaths: ['author'],
                ),
                FakeTypeMetadata::resource(type: 'people', fields: [Id::make()->build(), Str::make('name')->build()]),
            ],
            profiles: $profiles,
        );
    }

    /**
     * @param list<string> $profiles
     */
    private function bareServer(array $profiles): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [FakeTypeMetadata::resource(type: 'notes', fields: [Id::make()->build(), Str::make('body')->build()])],
            profiles: $profiles,
        );
    }

    private function projector(): OpenApiProjector
    {
        return new OpenApiProjector();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function paths(FakeServerMetadata $server): array
    {
        return $this->arrAt($this->projector()->project($server)->toArray(), 'paths');
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
            if (isset($parameter['name']) && \is_string($parameter['name'])) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }

    /**
     * The `$ref` strings among an operation's parameters (a component-parameter reference
     * carries a `$ref` rather than a `name`).
     *
     * @param array<array-key, mixed> $operation
     * @return list<string>
     */
    private function parameterRefs(array $operation): array
    {
        $refs = [];
        foreach ($this->listAt($operation, 'parameters') as $parameter) {
            self::assertIsArray($parameter);
            if (isset($parameter['$ref']) && \is_string($parameter['$ref'])) {
                $refs[] = $parameter['$ref'];
            }
        }

        return $refs;
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
}
