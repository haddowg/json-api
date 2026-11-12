<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\ErrorCoverage;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OAuthFlow;
use haddowg\JsonApi\OpenApi\OAuthFlows;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use haddowg\JsonApi\Tests\Exception\Fixture\PaymentRequired;
use haddowg\JsonApi\Tests\Exception\Fixture\SubscriptionExpired;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContributingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\MethodNotAllowedServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ReadOnlyAttributeRejectingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeDeclaringServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeTables;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The projected error-code catalogue: every code a server can raise is a `const` branch of
 * the one shape its status, `source` and `meta` select, and the shapes are offered from
 * `ErrorDocument.errors.items` by an `anyOf` whose first branch is the open generic `Error`.
 *
 * The load-bearing test is {@see anErrorCarryingAnUndocumentedCodeStillValidates}. The
 * catalogue is core's, not the world's — an application throws codes this projection
 * has never seen — so the moment the list became authoritative a server's own error
 * documents would fail its own published schema.
 */
#[CoversClass(ErrorCatalogProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[Group('spec:errors')]
final class ErrorCatalogProjectionTest extends TestCase
{
    // ---- The catalogue ------------------------------------------------------------

    #[Test]
    public function everyCodeAServerCanRaiseIsCatalogued(): void
    {
        $codes = $this->codes($this->schemas($this->fullServer()));

        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            self::assertArrayHasKey($descriptor->code, $codes, "{$descriptor->code} is not catalogued");
        }
    }

    #[Test]
    public function codesSharingAStatusSourceAndMetaShareOneShape(): void
    {
        $codes = $this->codes($this->schemas($this->fullServer()));

        self::assertSame('Error400Parameter', $codes['QUERY_PARAM_UNRECOGNIZED']);
        self::assertSame('Error400Parameter', $codes['FIELDSET_MEMBER_UNRECOGNIZED']);
        self::assertSame('Error404ResourceNotFound', $codes['RESOURCE_NOT_FOUND']);
        self::assertSame('Error400ResourceIdentifierIdInvalid', $codes['RESOURCE_IDENTIFIER_TYPE_MISSING']);
        self::assertSame('Error406Header', $codes['MEDIA_TYPE_UNACCEPTABLE']);
        self::assertSame('Error415Header', $codes['MEDIA_TYPE_UNSUPPORTED']);
        self::assertSame('Error403InsufficientScope', $codes['INSUFFICIENT_SCOPE']);
        self::assertSame('Error400RequestBodyInvalidJson', $codes['REQUEST_BODY_INVALID_JSON']);
    }

    #[Test]
    public function aDeclaredMetaIsClosedAndRequiredOnlyWhenAMemberIs(): void
    {
        $schemas = $this->schemas($this->fullServer());

        $scope = $this->narrowingFor($schemas, 'INSUFFICIENT_SCOPE');
        self::assertSame(['code', 'status', 'meta'], $this->listAt($scope, 'required'));
        self::assertFalse($this->at($scope, 'properties', 'meta', 'additionalProperties'));
        self::assertSame(['requiredScopes', 'missingScopes'], $this->listAt($scope, 'properties', 'meta', 'required'));
        self::assertSame(['requiredScopes', 'missingScopes', 'include'], \array_keys($this->arrAt($scope, 'properties', 'meta', 'properties')));

        $invalidJson = $this->narrowingFor($schemas, 'REQUEST_BODY_INVALID_JSON');
        self::assertSame(['code', 'status'], $this->listAt($invalidJson, 'required'));
        self::assertFalse($this->at($invalidJson, 'properties', 'meta', 'additionalProperties'));
        self::assertArrayHasKey('original', $this->arrAt($invalidJson, 'properties', 'meta', 'properties'));
    }

    #[Test]
    public function aShapeNarrowsTheSharedErrorByCodeAndStatus(): void
    {
        $schemas = $this->schemas($this->fullServer());
        $shape = $this->arrAt($schemas, 'Error400Parameter');

        $branches = $this->listAt($shape, 'allOf');
        self::assertSame(['$ref' => '#/components/schemas/Error'], $branches[0]);

        $branch = $this->branchFor($schemas, 'FILTERING_UNRECOGNIZED');
        self::assertSame('string', $branch['type']);
        self::assertSame('Filtering parameter is unrecognized', $branch['title']);

        $narrowing = $branches[1];
        self::assertIsArray($narrowing);
        self::assertSame('400', $this->at($narrowing, 'properties', 'status', 'const'));
        self::assertSame(['parameter'], $this->listAt($narrowing, 'properties', 'source', 'required'));
        self::assertSame(['code', 'status', 'source'], $this->listAt($narrowing, 'required'));
    }

    #[Test]
    public function theTitleStaysAStringSoAResolverMayReplaceIt(): void
    {
        // `code` / `status` are the machine contract and are pinned to a const; `title`
        // is copy an ErrorMessageResolver localizes, so pinning it would
        // publish a constraint the server itself breaks the moment one is bound.
        $narrowing = $this->narrowingFor($this->schemas($this->fullServer()), 'FILTERING_UNRECOGNIZED');

        self::assertArrayNotHasKey('title', $this->arrAt($narrowing, 'properties'));
    }

    #[Test]
    public function theInterpolationContextIsNotProjectedAtAll(): void
    {
        // Error::$context never reaches the wire — it is what core fills into the
        // title/detail templates before they are sent. A document describes what a client
        // receives, so the placeholder shape is neither a property nor an extension here;
        // ErrorDescriptor carries it for the PHP author writing a replacement template.
        $descriptor = $this->descriptorFor('INCLUSION_DEPTH_EXCEEDED');
        self::assertNotSame([], $descriptor->context, 'this code must declare placeholders for the test to mean anything');

        $schemas = $this->schemas($this->fullServer());

        self::assertArrayNotHasKey('x-error-context', $this->shapeFor($schemas, 'INCLUSION_DEPTH_EXCEEDED'));
        self::assertArrayNotHasKey('x-error-context', $this->branchFor($schemas, 'INCLUSION_DEPTH_EXCEEDED'));
        self::assertArrayNotHasKey('context', $this->arrAt($this->narrowingFor($schemas, 'INCLUSION_DEPTH_EXCEEDED'), 'properties'));
    }

    #[Test]
    public function theCatalogueIsOfferedFromTheErrorDocumentWithTheGenericBranchFirst(): void
    {
        $schemas = $this->schemas($this->fullServer());
        $branches = $this->listAt($schemas, 'ErrorDocument', 'properties', 'errors', 'items', 'anyOf');

        self::assertSame(['$ref' => '#/components/schemas/Error'], $branches[0]);
        $shapes = \array_values(\array_unique($this->codes($schemas)));
        self::assertEqualsCanonicalizing(
            \array_map(static fn(string $shape): array => ['$ref' => '#/components/schemas/' . $shape], $shapes),
            \array_slice($branches, 1),
        );
    }

    // ---- Contributed codes ----------------------------------------------------------

    #[Test]
    public function aServerContributedCodeIsCataloguedAlongsideCores(): void
    {
        $server = new ContributingServerMetadata(
            $this->fullServer(),
            new ClassListErrorSource(PaymentRequired::class),
        );

        $schemas = $this->schemas($server);

        $codes = $this->codes($schemas);
        self::assertSame('Error402', $codes['PAYMENT_REQUIRED'] ?? null);
        self::assertArrayHasKey('RESOURCE_NOT_FOUND', $codes);

        self::assertSame('402', $this->at($this->narrowingFor($schemas, 'PAYMENT_REQUIRED'), 'properties', 'status', 'const'));

        $branches = $this->listAt($schemas, 'ErrorDocument', 'properties', 'errors', 'items', 'anyOf');
        self::assertContains(['$ref' => '#/components/schemas/Error402'], $branches);
    }

    #[Test]
    public function aContributedCodeIsAbsentFromAServerThatDidNotRegisterIt(): void
    {
        // The catalogue is assembled per server, so one server's errors never leak into
        // another's document.
        self::assertArrayNotHasKey('PAYMENT_REQUIRED', $this->codes($this->schemas($this->fullServer())));
    }

    #[Test]
    public function aContributedCodeIsUngatedByDefault(): void
    {
        // ErrorFeature is core's closed enum of core's capabilities; a contributed error
        // names none, so it is published on every server that registers it.
        self::assertNull(PaymentRequired::describe()->feature);

        $server = new ContributingServerMetadata(
            $this->readOnlyServer(),
            new ClassListErrorSource(PaymentRequired::class),
        );

        self::assertArrayHasKey('PAYMENT_REQUIRED', $this->codes($this->schemas($server)));
    }

    // ---- Consumer-facing description ------------------------------------------------

    #[Test]
    public function aDescriptionIsProjectedOntoTheCodesBranch(): void
    {
        $server = new ContributingServerMetadata(
            $this->fullServer(),
            new ClassListErrorSource(SubscriptionExpired::class),
        );
        $schemas = $this->schemas($server);

        self::assertSame(SubscriptionExpired::describe()->description, $this->branchFor($schemas, 'SUBSCRIPTION_EXPIRED')['description'] ?? null);

        // The example detail lives in the error response's examples, not on the schema.
        $narrowing = $this->narrowingFor($schemas, 'SUBSCRIPTION_EXPIRED');
        self::assertArrayNotHasKey('detail', $this->arrAt($narrowing, 'properties'));
        self::assertSame(['code', 'status'], $this->listAt($narrowing, 'required'));
    }

    #[Test]
    public function coreAndContributedDescriptionsTakeTheSamePath(): void
    {
        $server = new ContributingServerMetadata(
            $this->fullServer(),
            new ClassListErrorSource(SubscriptionExpired::class),
        );
        $schemas = $this->schemas($server);

        foreach ([InclusionDepthExceeded::describe(), SubscriptionExpired::describe()] as $descriptor) {
            $branch = $this->branchFor($schemas, $descriptor->code);

            self::assertSame($descriptor->title, $branch['title'] ?? null);
            self::assertSame($descriptor->description, $branch['description'] ?? null);
        }
    }

    #[Test]
    public function everyCoreCodeBranchCarriesItsDescription(): void
    {
        $schemas = $this->schemas($this->fullServer());

        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            self::assertSame($descriptor->description, $this->branchFor($schemas, $descriptor->code)['description'] ?? null, $descriptor->code);
        }
    }

    #[Test]
    public function aCodeWithNoSourceOrMetaJoinsThePlainShapeOfItsStatus(): void
    {
        $server = new ContributingServerMetadata(
            $this->fullServer(),
            new ClassListErrorSource(PaymentRequired::class),
        );

        self::assertSame(
            [
                'title' => 'A 402 error',
                'description' => 'A `402` error. Its `code` is one of the branches below, each describing what the code means and what a client should do about it.',
                'allOf' => [
                    ['$ref' => '#/components/schemas/Error'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['anyOf' => [['type' => 'string', 'title' => 'Payment required', 'const' => 'PAYMENT_REQUIRED']]],
                            'status' => ['type' => 'string', 'const' => '402'],
                        ],
                        'required' => ['code', 'status'],
                    ],
                ],
            ],
            $this->arrAt($this->schemas($server), 'Error402'),
        );
    }

    #[Test]
    public function anErrorMatchingADescribedVariantStillValidates(): void
    {
        // The example is an annotation: a real detail that reads differently still validates.
        $server = new ContributingServerMetadata(
            $this->fullServer(),
            new ClassListErrorSource(SubscriptionExpired::class),
        );
        $document = (object) [
            'errors' => [
                (object) [
                    'status' => '402',
                    'code' => 'SUBSCRIPTION_EXPIRED',
                    'title' => 'Subscription expired',
                    'detail' => 'The basic subscription has expired.',
                ],
            ],
        ];

        self::assertTrue($this->validateErrorDocument($document, $server));
    }

    // ---- The open branch ----------------------------------------------------------

    #[Test]
    public function anErrorCarryingAnUndocumentedCodeStillValidates(): void
    {
        // An application's own error — a code core has never heard of, with a status no
        // catalogued variant claims. It must validate, or every server that throws
        // something of its own emits documents its own schema rejects.
        $document = (object) [
            'errors' => [
                (object) [
                    'status' => '418',
                    'code' => 'BREWING_REFUSED',
                    'title' => 'This server is a teapot',
                    'detail' => 'Short and stout.',
                    'source' => (object) ['pointer' => '/data/attributes/beverage'],
                ],
            ],
        ];

        self::assertTrue($this->validateErrorDocument($document));
    }

    #[Test]
    public function aCataloguedErrorValidates(): void
    {
        $document = (object) [
            'errors' => [
                (object) [
                    'status' => '400',
                    'code' => 'FILTERING_UNRECOGNIZED',
                    'title' => 'Filtering parameter is unrecognized',
                    'source' => (object) ['parameter' => 'filter[colour]'],
                ],
            ],
        ];

        self::assertTrue($this->validateErrorDocument($document));
    }

    #[Test]
    public function aDocumentWithNoErrorsIsStillRejected(): void
    {
        // Opening the code list must not open the document shape: `errors` is required
        // and non-empty regardless of which codes are catalogued.
        self::assertFalse($this->validateErrorDocument((object) ['errors' => []]));
        self::assertFalse($this->validateErrorDocument((object) ['meta' => (object) []]));
    }

    // ---- Registration-aware gating -------------------------------------------------

    #[Test]
    public function atomicCodesAppearOnlyWhenTheExtensionIsRegistered(): void
    {
        $this->assertGatedBy(
            ErrorFeature::AtomicOperations,
            $this->fullServer(),
            $this->fullServer(atomic: false),
        );
    }

    #[Test]
    public function cursorCodesAppearOnlyWhenACollectionPaginatesByCursor(): void
    {
        $paged = $this->fullServer(page: PagePaginator::make()->describePageSchema());

        $this->assertGatedBy(ErrorFeature::CursorPagination, $this->fullServer(), $paged);
    }

    #[Test]
    public function theCursorGateSeesACursorArmOfAPaginationMenu(): void
    {
        // The marker lives on the arm, not the menu, so the gate has to look inside.
        $schemas = $this->schemas($this->fullServer());

        self::assertArrayHasKey('CURSOR_STALE', $this->codes($schemas));
        self::assertArrayHasKey('PAGINATION_KIND_UNKNOWN', $this->codes($schemas));
    }

    #[Test]
    public function thePaginationMenuCodeAppearsOnlyWithAMenu(): void
    {
        $this->assertGatedBy(
            ErrorFeature::PaginationMenu,
            $this->fullServer(),
            $this->fullServer(page: CursorPaginator::make()->describePageSchema()),
        );
    }

    #[Test]
    public function theRelationshipCountCodeAppearsOnlyWhenTheCountableProfileIsRegistered(): void
    {
        $this->assertGatedBy(
            ErrorFeature::RelationshipCounts,
            $this->fullServer(),
            $this->fullServer(profiles: []),
        );
    }

    #[Test]
    public function clientIdCodesAppearOnlyWhenATypePermitsThem(): void
    {
        $this->assertGatedBy(
            ErrorFeature::ClientGeneratedIds,
            $this->fullServer(),
            $this->fullServer(clientIds: false),
        );
    }

    #[Test]
    public function requestDocumentCodesAreAbsentFromAReadOnlyServer(): void
    {
        $this->assertGatedBy(ErrorFeature::Writes, $this->fullServer(), $this->readOnlyServer());
    }

    #[Test]
    public function theAttributeWriteCodeAppearsOnlyWhenTheServerRejectsReadOnlyAttributes(): void
    {
        $this->assertGatedBy(
            ErrorFeature::ReadOnlyAttributeRejection,
            $this->fullServer(),
            $this->fullServer(rejectsReadOnlyAttributes: false),
        );

        $declinedSchemas = $this->schemas(new ReadOnlyAttributeRejectingServerMetadata($this->fullServer(rejectsReadOnlyAttributes: false), rejects: false));
        self::assertArrayNotHasKey('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($declinedSchemas));

        // Rejecting writes means nothing on a server that takes none.
        $readOnlySchemas = $this->schemas(new ReadOnlyAttributeRejectingServerMetadata($this->readOnlyServer()));
        self::assertArrayNotHasKey('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($readOnlySchemas));
    }

    #[Test]
    public function aServerThatDoesNotOptInProjectsTheSameDocument(): void
    {
        $plain = (new OpenApiProjector())->project($this->fullServer(rejectsReadOnlyAttributes: false))->toJson();
        $declined = (new OpenApiProjector())->project(new ReadOnlyAttributeRejectingServerMetadata($this->fullServer(rejectsReadOnlyAttributes: false), rejects: false))->toJson();

        self::assertEquals($plain, $declined);
    }

    #[Test]
    public function theMembershipCodeAppearsOnlyWhenARelationFixesItsMembers(): void
    {
        $this->assertGatedBy(
            ErrorFeature::FixedMembership,
            $this->fullServer(),
            $this->fullServer(fixedMembership: false),
        );

        $info = (new OpenApiProjector())->project($this->fullServer())->toArray()['info'] ?? null;
        self::assertIsArray($info);
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::FIXED_MEMBERSHIP)], $info['x-generator'] ?? null);
    }

    #[Test]
    public function theMethodNotAllowedCodeAppearsOnlyWhenTheServerRendersIt(): void
    {
        $this->assertGatedBy(
            ErrorFeature::MethodNotAllowed,
            $this->fullServer(),
            $this->fullServer(rendersMethodNotAllowed: false),
        );

        $declinedSchemas = $this->schemas(new MethodNotAllowedServerMetadata($this->fullServer(rendersMethodNotAllowed: false), renders: false));
        self::assertArrayNotHasKey('METHOD_NOT_ALLOWED', $this->codes($declinedSchemas));

        // A read-only server is where a client meets it most: every write is refused.
        $readOnlySchemas = $this->schemas(new MethodNotAllowedServerMetadata($this->readOnlyServer()));
        self::assertArrayHasKey('METHOD_NOT_ALLOWED', $this->codes($readOnlySchemas));
        self::assertSame('405', $this->at($this->narrowingFor($readOnlySchemas, 'METHOD_NOT_ALLOWED'), 'properties', 'status', 'const'));
    }

    #[Test]
    public function aServerThatDoesNotRenderMethodNotAllowedProjectsTheSameDocument(): void
    {
        $plain = (new OpenApiProjector())->project($this->readOnlyServer())->toJson();
        $declined = (new OpenApiProjector())->project(new MethodNotAllowedServerMetadata($this->readOnlyServer(), renders: false))->toJson();

        self::assertEquals($plain, $declined);
    }

    #[Test]
    public function aMutableRelationOnAReadOnlyTypeIsNotAWrite(): void
    {
        // The relation's mutation flags are permissive, but the type's allow-list has no
        // `Update` for a relationship mutation to ride on, so the projector emits no verb
        // that takes a linkage body — and the request-document codes stay out.
        $type = FakeTypeMetadata::resource(
            type: 'books',
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: [new FakeRelationMetadata('authors', ['people'], true, relatedEndpoint: false)],
            operations: [OperationType::FetchCollection, OperationType::FetchOne],
        );

        $schemas = $this->schemas(new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]));

        self::assertArrayNotHasKey('DATA_MEMBER_MISSING', $this->codes($schemas));
    }

    #[Test]
    public function aServerWithNoRegisteredFeatureStillPublishesTheUniversalCodes(): void
    {
        $schemas = $this->schemas($this->readOnlyServer());

        // Nothing gated survives; everything ungated does.
        $codes = $this->codes($schemas);
        self::assertArrayHasKey('RESOURCE_NOT_FOUND', $codes);
        self::assertArrayHasKey('QUERY_PARAM_UNRECOGNIZED', $codes);
        self::assertArrayNotHasKey('LOCAL_ID_CONFLICT', $codes);

        $universal = \array_map(
            static fn(ErrorDescriptor $descriptor): string => $descriptor->code,
            \array_values(\array_filter(
                ErrorCatalog::core()->descriptors(),
                static fn(ErrorDescriptor $descriptor): bool => $descriptor->feature === null,
            )),
        );
        // The type declares no filter, no sort and no relation, so no operation can raise
        // the codes that need one.
        // No type declares a handler refusing `include` either.
        $unreachable = ['FILTER_VALUE_INVALID', 'SORTING_UNRECOGNIZED', 'RELATIONSHIP_NOT_EXISTS', 'INCLUSION_UNSUPPORTED'];
        self::assertEqualsCanonicalizing(\array_values(\array_diff($universal, $unreachable)), \array_keys($codes));
    }

    // ---- Fixtures & helpers ---------------------------------------------------------

    /**
     * The catalogued descriptor for one code.
     */
    private function descriptorFor(string $code): ErrorDescriptor
    {
        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            if ($descriptor->code === $code) {
                return $descriptor;
            }
        }

        self::fail("no catalogued descriptor for {$code}");
    }

    /**
     * Asserts every code gated on `$feature` is present in `$offering`'s document and
     * absent from `$withholding`'s, and that no ungated code moved between the two.
     */
    private function assertGatedBy(ErrorFeature $feature, ServerMetadataInterface $offering, ServerMetadataInterface $withholding): void
    {
        $offered = $this->codes($this->schemas($offering));
        $withheld = $this->codes($this->schemas($withholding));

        $gated = 0;
        $catalog = ErrorCatalog::core();
        foreach (\array_combine($catalog->exceptions(), $catalog->descriptors()) as $class => $descriptor) {
            $component = $descriptor->code;

            if ($descriptor->feature === $feature) {
                ++$gated;
                self::assertArrayHasKey($component, $offered, "{$component} should be offered");
                self::assertArrayNotHasKey($component, $withheld, "{$component} should be withheld");
                continue;
            }

            // An ungated code a rule narrows can come and go with the operations either
            // server exposes; one every operation raises cannot.
            if ($descriptor->feature === null && ErrorCoverage::coversEveryOperation($class)) {
                self::assertArrayHasKey($component, $offered);
                self::assertArrayHasKey($component, $withheld);
            }
        }

        self::assertGreaterThan(0, $gated, 'no code is gated on ' . $feature->value);
    }

    /**
     * A server reaching every feature gate: the atomic extension, a pagination menu with
     * a cursor arm, the Countable profile, client-generated ids, a full write surface,
     * read-only attribute rejection, an operation that needs an OAuth scope and a
     * rendered method-not-allowed.
     *
     * @param list<string>|null $profiles
     */
    private function fullServer(
        bool $atomic = true,
        bool $clientIds = true,
        ?Schema $page = null,
        ?array $profiles = null,
        bool $rejectsReadOnlyAttributes = true,
        bool $rendersMethodNotAllowed = true,
        bool $fixedMembership = true,
    ): ServerMetadataInterface {
        $type = FakeTypeMetadata::resource(
            type: 'books',
            // An encoded id and a read-only attribute, so an undecodable client id and a
            // prohibited attribute write can arise.
            fields: [Id::make()->encodeUsing(new ReversingIdEncoder())->build(), Str::make('title')->build(), Str::make('isbn')->readOnly()->build(), Str::make('publisherName')->on('publisher')->build()],
            relations: [
                new FakeRelationMetadata('authors', ['people'], true, relatedEndpoint: false, countable: true, fixesMembership: $fixedMembership),
                new FakeRelationMetadata('publisher', ['people'], false),
            ],
            allowsClientId: $clientIds,
            requiresClientId: $clientIds,
            pageSchema: $page ?? (new MultiPaginator(PagePaginator::make(), CursorPaginator::make()))->describePageSchema(),
            countable: true,
            filters: [Where::make('title')->build()],
            sorts: [SortByField::make('title')],
            rejectsIncludeOn: ['fetchCollection'],
        );
        // A type refusing client ids, with no sorts, so the codes only it raises are reached.
        $people = FakeTypeMetadata::resource(
            type: 'people',
            fields: [Id::make()->build(), Str::make('name')->build()],
        );

        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$type, $people],
            atomicOperations: $atomic ? new FakeAtomicOperationsMetadata('/operations', 'Atomic') : null,
            profiles: $profiles ?? [CountableProfile::URI],
        );

        $server = new ScopeDeclaringServerMetadata(
            $server,
            ScopeTables::of($server, static fn(string $type, OperationKey $operation): array => $operation->kind === OperationKind::FetchOne ? ['books:read'] : []),
            schemes: ['oauth2' => SecurityScheme::oauth2(new OAuthFlows(clientCredentials: new OAuthFlow(['books:read' => 'Read books.'], tokenUrl: 'https://auth.example.com/token')))],
        );

        $server = $rejectsReadOnlyAttributes ? new ReadOnlyAttributeRejectingServerMetadata($server) : $server;

        return $rendersMethodNotAllowed ? new MethodNotAllowedServerMetadata($server) : $server;
    }

    /**
     * A server that accepts no request document anywhere: reads only, no relations to
     * mutate, no atomic endpoint.
     */
    private function readOnlyServer(): FakeServerMetadata
    {
        $type = FakeTypeMetadata::resource(
            type: 'books',
            fields: [Id::make()->build(), Str::make('title')->build()],
            operations: [OperationType::FetchCollection, OperationType::FetchOne],
        );

        return new FakeServerMetadata(title: 'API', version: '1.0.0', types: [$type]);
    }

    /**
     * Validates `$document` against the projected `ErrorDocument`, with the whole
     * component set registered so every `$ref` resolves.
     */
    private function validateErrorDocument(object $document, ?ServerMetadataInterface $server = null): bool
    {
        $projected = (new OpenApiProjector())->project($server ?? $this->fullServer())->toJson();

        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $id = 'urn:haddowg:jsonapi:test:error-document';
        $projected->{'$id'} = $id;
        $resolver->registerRaw($projected, $id);

        return $validator->validate(Helper::toJSON($document), $id . '#/components/schemas/ErrorDocument')->isValid();
    }

    /**
     * Every catalogued code, mapped to the shape component whose `code` branches hold it.
     *
     * @param array<array-key, mixed> $schemas
     *
     * @return array<string, string>
     */
    private function codes(array $schemas): array
    {
        $codes = [];
        foreach ($schemas as $name => $schema) {
            $branches = self::dig($schema, 'allOf', 1, 'properties', 'code', 'anyOf');
            if (!\is_array($branches)) {
                continue;
            }
            foreach ($branches as $branch) {
                if (\is_array($branch) && \is_string($branch['const'] ?? null)) {
                    $codes[$branch['const']] = (string) $name;
                }
            }
        }

        return $codes;
    }

    /**
     * The shape component cataloguing `$code`.
     *
     * @param array<array-key, mixed> $schemas
     *
     * @return array<array-key, mixed>
     */
    private function shapeFor(array $schemas, string $code): array
    {
        $shape = $this->codes($schemas)[$code] ?? null;
        self::assertNotNull($shape, "{$code} is not catalogued");

        return $this->arrAt($schemas, $shape);
    }

    /**
     * The narrowing (`allOf[1]`) of the shape cataloguing `$code`.
     *
     * @param array<array-key, mixed> $schemas
     *
     * @return array<array-key, mixed>
     */
    private function narrowingFor(array $schemas, string $code): array
    {
        return $this->arrAt($this->shapeFor($schemas, $code), 'allOf', '1');
    }

    /**
     * `$code`'s own `const` branch of its shape's `code`.
     *
     * @param array<array-key, mixed> $schemas
     *
     * @return array<array-key, mixed>
     */
    private function branchFor(array $schemas, string $code): array
    {
        foreach ($this->listAt($this->narrowingFor($schemas, $code), 'properties', 'code', 'anyOf') as $branch) {
            if (\is_array($branch) && ($branch['const'] ?? null) === $code) {
                return $branch;
            }
        }

        self::fail("{$code} has no branch");
    }

    /**
     * @return array<array-key, mixed>
     */
    private function schemas(ServerMetadataInterface $server): array
    {
        return $this->arrAt((new OpenApiProjector())->project($server)->toArray(), 'components', 'schemas');
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

    private static function dig(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
