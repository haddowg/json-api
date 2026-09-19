<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\ErrorResponseProjector;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
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
 * The shared error responses: one `components.responses` entry per advertised status,
 * `$ref`'d by every operation, each pointing at the narrowest error document the
 * catalogue supports.
 *
 * Two tests carry the design. {@see anErrorCarryingAnUndocumentedCodeStillValidates}
 * repeats the [ADR 0136](../../docs/adr/0136-the-projected-error-code-catalogue-is-open.md)
 * guard against the *narrowed* documents, where closing the list would be far more
 * tempting and just as wrong. {@see anErrorWhoseOwnStatusDiffersStillValidates} covers
 * the case that makes the narrowing deliberately non-exhaustive: a document of mixed
 * statuses takes the class they round down to
 * ([ADR 0018](../../docs/adr/0018-error-document-status-reflects-a-uniform-error-set.md)),
 * so a `400` body can legitimately carry a `422` error object.
 */
#[CoversClass(ErrorResponseProjector::class)]
#[CoversClass(ErrorCatalogProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[Group('spec:errors')]
final class ErrorResponseProjectionTest extends TestCase
{
    // ---- Extraction into components.responses ---------------------------------------

    #[Test]
    public function everyErrorResponseInTheDocumentIsARefIntoComponentsResponses(): void
    {
        $document = $this->project($this->fullServer());
        $components = $this->arrAt($document, 'components', 'responses');

        $seen = 0;
        foreach ($this->arrAt($document, 'paths') as $path => $item) {
            self::assertIsArray($item);
            foreach ($item as $method => $operation) {
                if (!\is_array($operation) || !isset($operation['responses'])) {
                    continue;
                }
                self::assertIsArray($operation['responses']);
                foreach ($operation['responses'] as $status => $response) {
                    if (!\str_starts_with((string) $status, '4') && !\str_starts_with((string) $status, '5')) {
                        continue;
                    }
                    ++$seen;
                    self::assertIsArray($response);
                    $where = "{$method} {$path} {$status}";
                    // Only a `$ref`, optionally with a description override — never an
                    // inline body.
                    self::assertSame([], \array_diff(\array_keys($response), ['$ref', 'description']), $where);
                    $name = \str_replace('#/components/responses/', '', $this->strAt($response, '$ref'));
                    self::assertArrayHasKey($name, $components, $where);
                }
            }
        }

        self::assertGreaterThan(0, $seen, 'the fixture server projects no error responses');
    }

    #[Test]
    public function aSharedResponseCarriesTheDescriptionAndTheJsonApiBodyTheOperationsUsedToInline(): void
    {
        $response = $this->arrAt($this->project($this->fullServer()), 'components', 'responses', 'UnprocessableEntity');

        self::assertSame('Unprocessable Entity — the document failed validation.', $this->strAt($response, 'description'));
        self::assertSame(
            '#/components/schemas/ErrorDocument422',
            $this->strAt($response, 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function onlyTheStatusesTheServerAdvertisesGetAResponseComponent(): void
    {
        // A read-only, unsecured server negotiates and reads; it never writes, so it
        // advertises no 409/415/422, and no 401.
        $components = $this->arrAt($this->project($this->readOnlyServer()), 'components', 'responses');

        self::assertSame(
            ['BadRequest', 'Forbidden', 'NotFound', 'NotAcceptable', 'InternalServerError'],
            \array_keys($components),
        );
    }

    #[Test]
    public function theAtomicBatchOverridesOnlyTheWordingItPhrasesDifferently(): void
    {
        // OAS 3.1 lets a Reference Object override the component's description, which is
        // what earns the atomic endpoint its own phrasing without its own components.
        $operation = $this->arrAt($this->project($this->fullServer()), 'paths', '/operations', 'post', 'responses');

        self::assertSame(
            ['$ref' => '#/components/responses/NotFound', 'description' => 'Not Found — an operation targets a resource that does not exist.'],
            $this->arrAt($operation, '404'),
        );
        // 403 reads the same on the batch as everywhere else, so it references the
        // component bare rather than restating it.
        self::assertSame(['$ref' => '#/components/responses/Forbidden'], $this->arrAt($operation, '403'));
    }

    // ---- Per-status narrowing --------------------------------------------------------

    #[Test]
    public function aNarrowedDocumentOffersOnlyTheCodesOfItsStatusBehindTheGenericBranch(): void
    {
        $schemas = $this->arrAt($this->project($this->fullServer()), 'components', 'schemas');
        $branches = $this->listAt($schemas, 'ErrorDocument415', 'properties', 'errors', 'items', 'anyOf');

        self::assertSame(['$ref' => '#/components/schemas/Error'], $branches[0], 'the generic branch must lead');

        $expected = [];
        foreach (ErrorCatalog::core()->descriptors() as $descriptor) {
            $component = ErrorCatalogProjector::componentName($descriptor->code);
            if ($descriptor->status === 415 && isset($schemas[$component])) {
                $expected[] = ['$ref' => '#/components/schemas/' . $component];
            }
        }

        self::assertNotSame([], $expected, 'no catalogued code carries 415');
        self::assertSame($expected, \array_slice($branches, 1));
    }

    #[Test]
    public function aStatusTheCatalogueClaimsNoCodeForKeepsTheGenericDocument(): void
    {
        $document = $this->project($this->fullServer());

        // 401 is the permanent case: no descriptor declares that status, because the
        // firewall returns it before any of core's exceptions can be raised.
        self::assertSame(
            '#/components/schemas/ErrorDocument',
            $this->strAt($document, 'components', 'responses', 'Unauthorized', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
        self::assertArrayNotHasKey('ErrorDocument401', $this->arrAt($document, 'components', 'schemas'));

        // And the same fallback catches a status whose codes the server's feature set
        // gated out: every 403 code core catalogues depends on a write surface, which a
        // read-only server has none of.
        $readOnly = $this->project($this->readOnlyServer());
        self::assertSame([], (new ErrorCatalogProjector())->componentsByStatus($this->readOnlyServer())[403] ?? []);
        self::assertSame(
            '#/components/schemas/ErrorDocument',
            $this->strAt($readOnly, 'components', 'responses', 'Forbidden', 'content', 'application/vnd.api+json', 'schema', '$ref'),
        );
    }

    #[Test]
    public function aNarrowingIsEmittedOnlyForAnAdvertisedStatus(): void
    {
        // The read-only server catalogues a 415 code but never advertises a 415 response,
        // so the narrowing that would describe it is not emitted either.
        $server = $this->readOnlyServer();
        self::assertNotSame([], (new ErrorCatalogProjector())->componentsByStatus($server)[415] ?? []);
        self::assertArrayNotHasKey('ErrorDocument415', $this->arrAt($this->project($server), 'components', 'schemas'));
    }

    // ---- The open branch, per status --------------------------------------------------

    #[Test]
    public function aCataloguedErrorValidatesAgainstItsStatusNarrowing(): void
    {
        self::assertTrue($this->validateAgainst('ErrorDocument400', (object) [
            'errors' => [
                (object) [
                    'status' => '400',
                    'code' => 'FILTERING_UNRECOGNIZED',
                    'title' => 'Filtering parameter is unrecognized',
                    'source' => (object) ['parameter' => 'filter[colour]'],
                ],
            ],
        ]));
    }

    #[Test]
    public function anErrorCarryingAnUndocumentedCodeStillValidates(): void
    {
        // The narrowed document is a sharper vocabulary, not a closed set. An
        // application's own 400 must validate against `ErrorDocument400` for the same
        // reason it must validate against the generic one.
        self::assertTrue($this->validateAgainst('ErrorDocument400', (object) [
            'errors' => [
                (object) [
                    'status' => '400',
                    'code' => 'BREWING_REFUSED',
                    'title' => 'This server is a teapot',
                    'source' => (object) ['pointer' => '/data/attributes/beverage'],
                ],
            ],
        ]));
    }

    #[Test]
    public function anErrorWhoseOwnStatusDiffersStillValidates(): void
    {
        // ADR 0018: a document whose errors carry different statuses takes the status
        // class they round down to, so a 400 body can carry a 422 error object. The
        // generic branch absorbs it — which is exactly why the narrowing is a catalogue
        // of what a status usually carries and not a promise about what it always does.
        self::assertTrue($this->validateAgainst('ErrorDocument400', (object) [
            'errors' => [
                (object) ['status' => '422', 'code' => 'VALIDATION_FAILED', 'title' => 'Invalid'],
                (object) ['status' => '409', 'code' => 'CONFLICT', 'title' => 'Conflicting'],
            ],
        ]));
    }

    #[Test]
    public function aNarrowedDocumentStillRejectsAMalformedBody(): void
    {
        // Opening the code list must not open the document shape.
        self::assertFalse($this->validateAgainst('ErrorDocument400', (object) ['errors' => []]));
        self::assertFalse($this->validateAgainst('ErrorDocument400', (object) ['meta' => (object) []]));
    }

    // ---- Fixtures & helpers ------------------------------------------------------------

    /**
     * Validates `$document` against one projected component, with the whole component
     * set registered so every `$ref` resolves.
     */
    private function validateAgainst(string $component, object $document): bool
    {
        $projected = (new OpenApiProjector())->project($this->fullServer())->toJson();

        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $id = 'urn:haddowg:jsonapi:test:error-response';
        $projected->{'$id'} = $id;
        $resolver->registerRaw($projected, $id);

        return $validator->validate(Helper::toJSON($document), $id . '#/components/schemas/' . $component)->isValid();
    }

    /**
     * A server reaching every status: the full write surface plus the atomic extension
     * (which phrases six of its errors its own way), secured so 401 is advertised.
     */
    private function fullServer(): FakeServerMetadata
    {
        $type = FakeTypeMetadata::resource(
            type: 'books',
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: [new FakeRelationMetadata('authors', ['people'], true, relatedEndpoint: false, countable: true)],
            allowsClientId: true,
            pageSchema: (new MultiPaginator(PagePaginator::make(), CursorPaginator::make()))->describePageSchema(),
            countable: true,
        );

        return new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [$type],
            atomicOperations: new FakeAtomicOperationsMetadata('/operations', 'Atomic'),
            profiles: [CountableProfile::URI],
            defaultSecurity: [SecurityRequirement::scheme('bearer')],
        );
    }

    /**
     * A server that reads and negotiates and nothing else: no writes, no security.
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
     * @return array<array-key, mixed>
     */
    private function project(ServerMetadataInterface $server): array
    {
        return (new OpenApiProjector())->project($server)->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private function at(array $document, string ...$keys): mixed
    {
        $cursor = $document;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    private function arrAt(array $document, string ...$keys): array
    {
        $value = $this->at($document, ...$keys);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $document
     * @return list<mixed>
     */
    private function listAt(array $document, string ...$keys): array
    {
        return \array_values($this->arrAt($document, ...$keys));
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private function strAt(array $document, string ...$keys): string
    {
        $value = $this->at($document, ...$keys);
        self::assertIsString($value);

        return $value;
    }
}
