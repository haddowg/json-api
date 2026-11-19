<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationExampleProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\TypedDocumentMeta;
use haddowg\JsonApi\OpenApi\TypedRequestMeta;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\TypedRequestMetaTypes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A write declaring its request `meta` takes a request document of its own, a type declaring
 * its resource objects' `meta` types it in each direction, a relationship write or a `202`
 * declaring its document's `meta` answers a document of its own, and every declared `meta`
 * object is closed unless an `additionalProperties` entry opens it.
 */
#[CoversClass(TypedRequestMeta::class)]
#[CoversClass(TypedDocumentMeta::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(OperationExampleProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(SchemaProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class TypedRequestMetaProjectionTest extends TestCase
{
    #[Test]
    public function aCreateNarrowsItsSharedRequestToTheDeclaredMeta(): void
    {
        $document = self::project();

        self::assertSame([
            'allOf' => [
                ['$ref' => '#/components/schemas/BookingsCreateRequest'],
                [
                    'type' => 'object',
                    'properties' => ['meta' => [
                        'type' => 'object',
                        'properties' => ['idempotencyKey' => ['type' => 'string', 'minLength' => 1, 'examples' => ['k-1']]],
                        'required' => ['idempotencyKey'],
                        'additionalProperties' => false,
                    ]],
                    'required' => ['meta'],
                ],
            ],
            'x-request-meta' => true,
        ], self::schema($document, 'BookingsCreateRequestDocument'));

        $body = self::arr(self::operation($document, '/bookings', 'post')['requestBody']);
        $media = self::arr(self::arr($body['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsCreateRequestDocument'], $media['schema']);
        self::assertSame(['idempotencyKey' => 'k-1'], self::arr($media['example'])['meta']);
        self::assertSame(['source' => 'web'], self::arr(self::arr($media['example'])['data'])['meta']);

        // The shared request stays as it was, so an action taking the type's document is unaffected.
        self::assertArrayNotHasKey('x-request-meta', self::schema($document, 'BookingsCreateRequest'));
    }

    #[Test]
    public function anUpdateWithNoRequiredMemberLeavesMetaOptional(): void
    {
        $narrowing = self::arr(self::arr(self::schema(self::project(), 'BookingsUpdateRequestDocument')['allOf'])[1]);

        self::assertArrayNotHasKey('required', $narrowing);
        self::assertSame(['reason'], \array_keys(self::arr(self::arr(self::arr($narrowing['properties'])['meta'])['properties'])));
    }

    #[Test]
    public function aDeleteDeclaringRequestMetaTakesAMetaOnlyBody(): void
    {
        $document = self::project();

        $schema = self::schema($document, 'BookingsDeleteRequestDocument');
        self::assertSame(['meta'], $schema['required']);
        self::assertSame(['meta', 'jsonapi'], \array_keys(self::arr($schema['properties'])));
        self::assertTrue($schema['x-request-meta']);

        $delete = self::operation($document, '/bookings/{id}', 'delete');
        $body = self::arr($delete['requestBody']);
        self::assertTrue($body['required']);
        self::assertSame(['cancellationReason' => 'ill'], self::arr(self::arr(self::arr(self::arr($body['content'])['application/vnd.api+json'])['example'])['meta']));
        self::assertArrayHasKey('415', self::arr($delete['responses']));
        self::assertArrayHasKey('422', self::arr($delete['responses']));

        // A delete declaring nothing still takes no body.
        $rooms = self::operation($document, '/rooms/{id}', 'delete');
        self::assertArrayNotHasKey('requestBody', $rooms);
        self::assertArrayNotHasKey('415', self::arr($rooms['responses']));
    }

    #[Test]
    public function eachRelationshipWriteTakesItsOwnRequestDocument(): void
    {
        $document = self::project();

        foreach (['patch' => 'BookingsRoomsUpdateRelationshipRequestDocument', 'post' => 'BookingsRoomsAddRelationshipRequestDocument', 'delete' => 'BookingsRoomsRemoveRelationshipRequestDocument'] as $method => $component) {
            $schema = self::schema($document, $component);
            self::assertSame(['$ref' => '#/components/schemas/BookingsRoomsRelationshipRequest'], self::arr($schema['allOf'])[0], $component);
            // The entry opens the object to any member.
            self::assertTrue(self::arr(self::arr(self::arr(self::arr(self::arr($schema['allOf'])[1])['properties'])['meta']))['additionalProperties']);

            $media = self::arr(self::arr(self::arr(self::operation($document, '/bookings/{id}/relationships/rooms', $method)['requestBody'])['content'])['application/vnd.api+json']);
            self::assertSame(['$ref' => '#/components/schemas/' . $component], $media['schema'], $method);
        }

        // A relation the type declares nothing for keeps its shared document.
        $guest = self::arr(self::arr(self::arr(self::operation($document, '/bookings/{id}/relationships/guest', 'patch')['requestBody'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsGuestRelationshipDocument'], $guest['schema']);
    }

    #[Test]
    public function anActionTypesItsRequestMetaPerMethod(): void
    {
        $document = self::project();

        // Mounted on two methods, the action names its request documents by method.
        $post = self::schema($document, 'BookingsConfirmActionPostRequestDocument');
        self::assertSame(['silent'], \array_keys(self::arr(self::arr(self::arr($post['properties'])['meta'])['properties'])));
        self::assertArrayNotHasKey('BookingsConfirmActionPatchRequestDocument', self::arr(self::arr($document['components'])['schemas']));

        $postBody = self::arr(self::operation($document, '/bookings/{id}/-actions/confirm', 'post')['requestBody']);
        self::assertFalse($postBody['required']);
        self::assertSame(['meta' => ['silent' => true]], self::arr(self::arr($postBody['content'])['application/vnd.api+json'])['example']);
        self::assertArrayNotHasKey('requestBody', self::operation($document, '/bookings/{id}/-actions/confirm', 'patch'));

        // A document-input action narrows its input type's create request.
        $quote = self::schema($document, 'BookingsQuoteActionRequestDocument');
        self::assertSame(['$ref' => '#/components/schemas/BookingsCreateRequest'], self::arr($quote['allOf'])[0]);
        $media = self::arr(self::arr(self::arr(self::operation($document, '/bookings/-actions/quote', 'post')['requestBody'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsQuoteActionRequestDocument'], $media['schema']);
        self::assertSame(['currency' => 'EUR'], self::arr($media['example'])['meta']);
    }

    #[Test]
    public function resourceObjectMetaIsTypedInEachDirection(): void
    {
        $document = self::project();

        self::assertSame([
            'type' => 'object',
            'properties' => [
                'nights' => ['type' => ['integer', 'null'], 'examples' => [3]],
                'heldUntil' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
            'additionalProperties' => ['type' => ['string', 'null']],
            'x-resource-meta' => true,
        ], self::schema($document, 'BookingsResourceMeta'));
        foreach (['BookingsCreateResourceMeta', 'BookingsUpdateResourceMeta'] as $component) {
            self::assertSame([
                'type' => 'object',
                'properties' => ['source' => ['type' => 'string', 'examples' => ['web']]],
                'additionalProperties' => ['type' => 'string'],
                'x-resource-meta' => true,
            ], self::schema($document, $component), $component);
        }

        self::assertSame(['$ref' => '#/components/schemas/BookingsResourceMeta'], self::arr(self::schema($document, 'BookingsResource')['properties'])['meta']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsCreateResourceMeta'], self::dataMeta(self::schema($document, 'BookingsCreateRequest')));
        self::assertSame(['$ref' => '#/components/schemas/BookingsUpdateResourceMeta'], self::dataMeta(self::schema($document, 'BookingsUpdateRequest')));
        // The atomic resource objects carry the atomic declaration inline.
        self::assertSame(['source'], \array_keys(self::arr(self::arr(self::arr(self::schema($document, 'BookingsAtomicAdd')['properties'])['meta'])['properties'])));
        self::assertSame(['source'], \array_keys(self::arr(self::arr(self::arr(self::schema($document, 'BookingsAtomicUpdate')['properties'])['meta'])['properties'])));

        // A resource example renders the read members that declare an example.
        $fetched = self::arr(self::arr(self::arr(self::arr(self::operation($document, '/bookings/{id}', 'get')['responses'])['200'])['content'])['application/vnd.api+json']);
        self::assertSame(['nights' => 3], self::arr(self::arr(self::arr($fetched['example'])['data'])['meta']));

        // A type declaring nothing keeps the shared `Meta`.
        self::assertSame(['$ref' => '#/components/schemas/Meta'], self::arr(self::schema($document, 'RoomsResource')['properties'])['meta']);
        self::assertArrayNotHasKey('meta', self::arr(self::arr(self::arr(self::schema($document, 'RoomsCreateRequest')['properties'])['data'])['properties']));
    }

    #[Test]
    public function aTypeDeclaringAnAtomicOperationsMetaTakesAKindOfItsOwn(): void
    {
        $document = self::project();
        $schemas = self::arr(self::arr($document['components'])['schemas']);

        $add = self::schema($document, 'BookingsAtomicAddOperation');
        self::assertSame(['$ref' => '#/components/schemas/BookingsAtomicAdd'], self::arr($add['properties'])['data']);
        self::assertSame(['op', 'data', 'meta'], $add['required']);
        self::assertTrue($add['x-request-meta']);
        // The shared add no longer offers the declaring type.
        self::assertSame(['rooms' => '#/components/schemas/RoomsAtomicAdd'], self::arr(self::arr(self::arr(self::schema($document, 'AtomicAddOperation')['properties'])['data'])['discriminator'])['mapping']);

        // A remove and a relationship operation are told apart by their ref.
        $remove = self::schema($document, 'BookingsAtomicRemoveOperation');
        self::assertFalse(self::arr($remove['properties'])['href']);
        self::assertSame(['op', 'ref', 'meta'], $remove['required']);
        self::assertSame(
            ['not' => ['properties' => ['type' => ['type' => 'string', 'const' => 'bookings']], 'required' => ['type']]],
            self::arr(self::arr(self::arr(self::schema($document, 'AtomicRemoveOperation')['properties'])['ref'])['allOf'])[1],
        );
        self::assertSame(
            ['not' => ['properties' => ['type' => ['type' => 'string', 'const' => 'bookings'], 'relationship' => ['type' => 'string', 'const' => 'rooms']], 'required' => ['type', 'relationship']]],
            self::arr(self::arr(self::arr(self::schema($document, 'AtomicRelationshipAddOperation')['properties'])['ref'])['allOf'])[1],
        );
        self::assertArrayHasKey('BookingsRoomsAtomicRelationshipAddOperation', $schemas);
        self::assertArrayHasKey('BookingsRoomsAtomicRelationshipUpdateOperation', $schemas);
        self::assertArrayHasKey('BookingsRoomsAtomicRelationshipRemoveOperation', $schemas);
        self::assertContains(['$ref' => '#/components/schemas/BookingsAtomicAddOperation'], self::arr(self::schema($document, 'AtomicOperation')['oneOf']));
    }

    #[Test]
    public function aRelationshipWriteDeclaringItsDocumentMetaAnswersAMetaOnlyDocument(): void
    {
        $document = self::project();

        self::assertSame(['$ref' => '#/components/schemas/MetaDocument'], self::arr(self::schema($document, 'BookingsRoomsAddRelationshipDocument')['allOf'])[0]);
        $media = self::arr(self::arr(self::arr(self::arr(self::operation($document, '/bookings/{id}/relationships/rooms', 'post')['responses'])['200'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsRoomsAddRelationshipDocument'], $media['schema']);
        self::assertSame(['moved' => 1], self::arr($media['example'])['meta']);

        // The other mutations declare none and still answer the linkage.
        $patch = self::arr(self::arr(self::arr(self::arr(self::operation($document, '/bookings/{id}/relationships/rooms', 'patch')['responses'])['200'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsRoomsRelationshipDocument'], $patch['schema']);
    }

    #[Test]
    public function aStatus202DeclaringItsMetaAnswersADocumentOfItsOwn(): void
    {
        $document = self::project();

        self::assertSame(['$ref' => '#/components/schemas/JobsDocument'], self::arr(self::schema($document, 'BookingsCreateAcceptedDocument')['allOf'])[0]);
        $create = self::arr(self::arr(self::arr(self::arr(self::operation($document, '/bookings', 'post')['responses'])['202'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsCreateAcceptedDocument'], $create['schema']);
        self::assertSame(['queuePosition' => 7], self::arr($create['example'])['meta']);

        $action = self::arr(self::arr(self::arr(self::arr(self::operation($document, '/bookings/{id}/-actions/confirm', 'post')['responses'])['202'])['content'])['application/vnd.api+json']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsConfirmActionAcceptedDocument'], $action['schema']);
    }

    #[Test]
    public function pivotAndIdentifierMetaAreClosedUnlessAnEntryOpensThem(): void
    {
        $document = self::project();

        self::assertSame(['type' => ['string', 'null']], self::schema($document, 'BookingsRoomsPivot')['additionalProperties']);
        self::assertSame(['type' => 'string'], self::schema($document, 'BookingsRoomsWritePivot')['additionalProperties']);
        $identifier = self::schema($document, 'BookingsRoomsIdentifierMeta');
        self::assertTrue($identifier['additionalProperties']);
        self::assertSame(['$ref' => '#/components/schemas/BookingsRoomsPivot'], self::arr($identifier['properties'])['pivot']);
    }

    #[Test]
    public function aRequiredMemberWithNoExampleDropsTheOperationsExample(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'rooms',
            fields: [Id::make()->example('rm-1')->build()],
            requestMeta: static fn(RequestMetaContext $context): array => $context->is(OperationType::Create) ? [Str::make('token')->required()] : [],
        );
        $projection = (new OpenApiProjector())->projectWithDiagnostics(new FakeServerMetadata('API', '1', [$type]));

        $misses = [];
        foreach ($projection->missingExamples as $miss) {
            $misses[] = $miss->operation . ' ' . $miss->field;
        }
        self::assertContains('create.rooms meta.token', $misses);
        $media = self::arr(self::arr(self::arr(self::operation($projection->document->toArray(), '/rooms', 'post')['requestBody'])['content'])['application/vnd.api+json']);
        self::assertArrayNotHasKey('example', $media);
    }

    #[Test]
    public function documentMetaAndTheEntryProjectAnOpenObject(): void
    {
        $type = FakeTypeMetadata::resource(
            type: 'rooms',
            fields: [Id::make()->example('rm-1')->build()],
            documentMeta: [OperationType::FetchOne->value => [Integer::make('views'), AdditionalProperties::make()->of(Integer::make('*'))]],
        );
        $document = (new OpenApiProjector())->project(new FakeServerMetadata('API', '1', [$type]))->toArray();

        $meta = self::arr(self::arr(self::arr(self::arr(self::schema($document, 'RoomsFetchOneDocument')['allOf'])[1])['properties'])['meta']);
        self::assertSame(['type' => ['integer', 'null']], $meta['additionalProperties']);
        self::assertSame(['contract' => GeneratorContract::TYPED_REQUEST_META], self::arr($document['info'])['x-generator']);
    }

    #[Test]
    public function aPivotTargetsResourceMetaOffersItsLinksPivot(): void
    {
        $rooms = FakeTypeMetadata::resource(
            type: 'rooms',
            fields: [Id::make()->example('rm-1')->build()],
            resourceMeta: static fn(): array => [Integer::make('floor')->readOnly()],
        );
        $document = (new OpenApiProjector())->project(new FakeServerMetadata('API', '1', [TypedRequestMetaTypes::bookings(), $rooms, TypedRequestMetaTypes::jobs()]))->toArray();

        $meta = self::schema($document, 'RoomsResourceMeta');
        self::assertSame(['floor', 'pivot'], \array_keys(self::arr($meta['properties'])));
        self::assertSame('object', self::arr(self::arr($meta['properties'])['pivot'])['type']);
        self::assertFalse($meta['additionalProperties']);

        // The bookings type is no pivot target, so its declared object has no `pivot`.
        self::assertArrayNotHasKey('pivot', self::arr(self::schema($document, 'BookingsResourceMeta')['properties']));
    }

    #[Test]
    public function typesDeclaringNothingCarryNoTypedMeta(): void
    {
        $document = (new OpenApiProjector())->project(new FakeServerMetadata('API', '1', [TypedRequestMetaTypes::rooms(), TypedRequestMetaTypes::jobs()], atomicOperations: new FakeAtomicOperationsMetadata()))->toArray();
        $json = \json_encode($document, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('x-request-meta', $json);
        self::assertStringNotContainsString('x-resource-meta', $json);
        self::assertStringNotContainsString('RequestDocument', $json);
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::arr($document['info'])['x-generator']);
    }

    #[Test]
    public function aDocumentCarryingTypedRequestMetaStampsItsTier(): void
    {
        self::assertSame(['contract' => GeneratorContract::TYPED_REQUEST_META], self::arr(self::project()['info'])['x-generator']);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function project(): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            'API',
            '1',
            [TypedRequestMetaTypes::bookings(), TypedRequestMetaTypes::rooms(), TypedRequestMetaTypes::jobs()],
            atomicOperations: new FakeAtomicOperationsMetadata(),
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private static function schema(array $document, string $name): array
    {
        return self::arr(self::arr(self::arr($document['components'])['schemas'])[$name] ?? null);
    }

    /**
     * @param array<array-key, mixed> $request
     */
    private static function dataMeta(array $request): mixed
    {
        return self::arr(self::arr(self::arr($request['properties'])['data'])['properties'])['meta'] ?? null;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private static function operation(array $document, string $path, string $method): array
    {
        return self::arr(self::arr(self::arr($document['paths'])[$path] ?? null)[$method] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
