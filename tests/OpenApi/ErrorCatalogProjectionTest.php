<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\Exception\Fixture\PaymentRequired;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContributingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The projected error-code catalogue: one named component per code a server can raise,
 * offered from `ErrorDocument.errors.items` by an `anyOf` whose first branch is the
 * open generic `Error`.
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
    public function everyCodeAServerCanRaiseIsANamedComponent(): void
    {
        $schemas = $this->schemas($this->fullServer());

        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            $component = ErrorCatalogProjector::componentName($descriptor->code);
            self::assertArrayHasKey($component, $schemas, "missing error component {$component}");
        }
    }

    #[Test]
    public function aVariantNarrowsTheSharedErrorByCodeAndStatus(): void
    {
        $variant = $this->arrAt($this->schemas($this->fullServer()), 'FilteringUnrecognizedError');

        self::assertSame('Filtering parameter is unrecognized', $variant['title']);

        $branches = $this->listAt($variant, 'allOf');
        self::assertSame(['$ref' => '#/components/schemas/Error'], $branches[0]);

        $narrowing = $branches[1];
        self::assertIsArray($narrowing);
        self::assertSame('FILTERING_UNRECOGNIZED', $this->at($narrowing, 'properties', 'code', 'const'));
        self::assertSame('400', $this->at($narrowing, 'properties', 'status', 'const'));
        self::assertSame(['parameter'], $this->listAt($narrowing, 'properties', 'source', 'required'));
        self::assertSame(['code', 'status', 'source'], $this->listAt($narrowing, 'required'));
    }

    #[Test]
    public function theTitleStaysAStringSoAResolverMayReplaceIt(): void
    {
        // `code` / `status` are the machine contract and are pinned to a const; `title`
        // is copy an ErrorMessageResolver localizes (ADR 0128), so pinning it would
        // publish a constraint the server itself breaks the moment one is bound.
        $narrowing = $this->listAt($this->schemas($this->fullServer()), 'FilteringUnrecognizedError', 'allOf')[1];
        self::assertIsArray($narrowing);

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

        $variant = $this->arrAt($this->schemas($this->fullServer()), 'InclusionDepthExceededError');

        self::assertArrayNotHasKey('x-error-context', $variant);

        $narrowing = $this->listAt($variant, 'allOf')[1];
        self::assertIsArray($narrowing);
        self::assertArrayNotHasKey('context', $this->arrAt($narrowing, 'properties'));
    }

    #[Test]
    public function theCatalogueIsOfferedFromTheErrorDocumentWithTheGenericBranchFirst(): void
    {
        $schemas = $this->schemas($this->fullServer());
        $branches = $this->listAt($schemas, 'ErrorDocument', 'properties', 'errors', 'items', 'anyOf');

        self::assertSame(['$ref' => '#/components/schemas/Error'], $branches[0]);
        self::assertCount(\count(ErrorCatalog::core()->descriptors()) + 1, $branches);
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

        self::assertArrayHasKey('PaymentRequiredError', $schemas);
        self::assertArrayHasKey('ResourceNotFoundError', $schemas);

        $narrowing = $this->listAt($schemas, 'PaymentRequiredError', 'allOf')[1];
        self::assertIsArray($narrowing);
        self::assertSame('PAYMENT_REQUIRED', $this->at($narrowing, 'properties', 'code', 'const'));
        self::assertSame('402', $this->at($narrowing, 'properties', 'status', 'const'));

        $branches = $this->listAt($schemas, 'ErrorDocument', 'properties', 'errors', 'items', 'anyOf');
        self::assertContains(['$ref' => '#/components/schemas/PaymentRequiredError'], $branches);
    }

    #[Test]
    public function aContributedCodeIsAbsentFromAServerThatDidNotRegisterIt(): void
    {
        // The catalogue is assembled per server, so one server's errors never leak into
        // another's document.
        self::assertArrayNotHasKey('PaymentRequiredError', $this->schemas($this->fullServer()));
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

        self::assertArrayHasKey('PaymentRequiredError', $this->schemas($server));
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

        self::assertArrayHasKey('CursorStaleError', $schemas);
        self::assertArrayHasKey('PaginationKindUnknownError', $schemas);
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

        self::assertArrayNotHasKey('DataMemberMissingError', $schemas);
    }

    #[Test]
    public function aServerWithNoRegisteredFeatureStillPublishesTheUniversalCodes(): void
    {
        $schemas = $this->schemas($this->readOnlyServer());

        // Nothing gated survives; everything ungated does.
        self::assertArrayHasKey('ResourceNotFoundError', $schemas);
        self::assertArrayHasKey('QueryParamUnrecognizedError', $schemas);
        self::assertArrayNotHasKey('LocalIdConflictError', $schemas);

        $universal = \array_filter(
            ErrorCatalog::core()->descriptors(),
            static fn(ErrorDescriptor $descriptor): bool => $descriptor->feature === null,
        );
        $branches = $this->listAt($schemas, 'ErrorDocument', 'properties', 'errors', 'items', 'anyOf');
        self::assertCount(\count($universal) + 1, $branches);
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
    private function assertGatedBy(ErrorFeature $feature, FakeServerMetadata $offering, FakeServerMetadata $withholding): void
    {
        $offered = $this->schemas($offering);
        $withheld = $this->schemas($withholding);

        $gated = 0;
        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            $component = ErrorCatalogProjector::componentName($descriptor->code);

            if ($descriptor->feature === $feature) {
                ++$gated;
                self::assertArrayHasKey($component, $offered, "{$component} should be offered");
                self::assertArrayNotHasKey($component, $withheld, "{$component} should be withheld");
                continue;
            }

            if ($descriptor->feature === null) {
                self::assertArrayHasKey($component, $offered);
                self::assertArrayHasKey($component, $withheld);
            }
        }

        self::assertGreaterThan(0, $gated, 'no code is gated on ' . $feature->value);
    }

    /**
     * A server reaching every feature gate: the atomic extension, a pagination menu with
     * a cursor arm, the Countable profile, client-generated ids and a full write surface.
     */
    /**
     * @param list<string>|null $profiles
     */
    private function fullServer(
        bool $atomic = true,
        bool $clientIds = true,
        ?Schema $page = null,
        ?array $profiles = null,
    ): FakeServerMetadata {
        $type = FakeTypeMetadata::resource(
            type: 'books',
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: [new FakeRelationMetadata('authors', ['people'], true, relatedEndpoint: false, countable: true)],
            allowsClientId: $clientIds,
            pageSchema: $page ?? (new MultiPaginator(PagePaginator::make(), CursorPaginator::make()))->describePageSchema(),
            countable: true,
        );

        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$type],
            atomicOperations: $atomic ? new FakeAtomicOperationsMetadata('/operations', 'Atomic') : null,
            profiles: $profiles ?? [CountableProfile::URI],
        );
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
    private function validateErrorDocument(object $document): bool
    {
        $projected = (new OpenApiProjector())->project($this->fullServer())->toJson();

        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $id = 'urn:haddowg:jsonapi:test:error-document';
        $projected->{'$id'} = $id;
        $resolver->registerRaw($projected, $id);

        return $validator->validate(Helper::toJSON($document), $id . '#/components/schemas/ErrorDocument')->isValid();
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
}
