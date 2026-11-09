<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ActionDocumentInput;
use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationExampleProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `Document` action's request body is a create-style request document for its input
 * type, which the runtime parses, validates and hydrates in a create context, whether or
 * not the type itself exposes create.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
#[CoversClass(OperationExampleProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(ActionDocumentInput::class)]
final class ActionDocumentInputProjectionTest extends TestCase
{
    #[Test]
    public function anInputTypeThatCannotBeCreatedStillGetsItsCreateRequest(): void
    {
        $document = $this->document();
        $schemas = $this->arrAt($document, 'components', 'schemas');

        self::assertArrayHasKey('OrdersCreateAttributes', $schemas);
        self::assertSame(['quantity'], $this->arrAt($schemas, 'OrdersCreateAttributes', 'required'));
        self::assertArrayNotHasKey('OrdersUpdateRequest', $schemas);
        self::assertArrayNotHasKey('OrdersUpdateAttributes', $schemas);

        $request = $this->arrAt($schemas, 'OrdersCreateRequest');
        self::assertSame(['data'], $request['required'] ?? null);
        $data = $this->arrAt($request, 'properties', 'data');
        self::assertSame(['const' => 'orders', 'type' => 'string'], self::sorted($this->arrAt($data, 'properties', 'type')));
        self::assertFalse($this->arrAt($data, 'properties')['id']);
        self::assertSame(['$ref' => '#/components/schemas/OrdersCreateAttributes'], $this->arrAt($data, 'properties', 'attributes'));
        self::assertSame(['product'], \array_keys($this->arrAt($data, 'properties', 'relationships', 'properties')));

        self::assertSame(['$ref' => '#/components/schemas/OrdersCreateRequest'], $this->bodySchema($document, '/carts/{id}/-actions/checkout'));
        self::assertSame(['$ref' => '#/components/schemas/OrdersCreateRequest'], $this->bodySchema($document, '/carts/{id}/-actions/import'));
    }

    #[Test]
    public function aTypeNoDocumentActionTakesGetsNoWriteComponents(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        self::assertArrayNotHasKey('CartsCreateRequest', $schemas);
        self::assertArrayNotHasKey('CartsCreateAttributes', $schemas);
    }

    #[Test]
    public function theInputsIdFollowsItsClientIdPolicy(): void
    {
        $schemas = $this->arrAt($this->document(), 'components', 'schemas');

        $optional = $this->arrAt($schemas, 'CouponsCreateRequest', 'properties', 'data');
        self::assertSame(['type' => 'string'], $this->arrAt($optional, 'properties', 'id'));
        self::assertSame(['type'], $optional['required'] ?? null);

        $required = $this->arrAt($schemas, 'VouchersCreateRequest', 'properties', 'data');
        self::assertSame(['type', 'id'], $required['required'] ?? null);
    }

    #[Test]
    public function aFieldlessTypeTakesAnOpenCreateRequestDocument(): void
    {
        $document = $this->document();
        $schemas = $this->arrAt($document, 'components', 'schemas');

        foreach (['NotesCreateRequest' => '/carts/{id}/-actions/annotate', 'PingsCreateRequest' => '/pings'] as $component => $path) {
            $data = $this->arrAt($schemas, $component, 'properties', 'data');
            self::assertSame(['type' => 'object'], $this->arrAt($data, 'properties', 'attributes'), $component);
            self::assertFalse($this->arrAt($data, 'properties')['id'], $component);
            self::assertSame(['$ref' => '#/components/schemas/' . $component], $this->bodySchema($document, $path), $path);
        }
        self::assertArrayNotHasKey('NotesCreateAttributes', $schemas);
    }

    #[Test]
    public function aFieldlessTypeTakesAnOpenUpdateRequestDocument(): void
    {
        $document = $this->document();
        $data = $this->arrAt($document, 'components', 'schemas', 'PingsUpdateRequest', 'properties', 'data');

        self::assertSame(['type' => 'object'], $this->arrAt($data, 'properties', 'attributes'));
        self::assertSame(['type' => 'string'], $this->arrAt($data, 'properties', 'id'));
        self::assertSame(['type', 'id'], $data['required'] ?? null);
        self::assertSame(['$ref' => '#/components/schemas/PingsUpdateRequest'], $this->arrAt($document, 'paths', '/pings/{id}', 'patch', 'requestBody', 'content', 'application/vnd.api+json', 'schema'));
    }

    #[Test]
    public function theActionsExampleIsACreateDocumentWithNoLinks(): void
    {
        $example = $this->arrAt($this->document(), 'paths', '/carts/{id}/-actions/checkout', 'post', 'requestBody', 'content', 'application/vnd.api+json', 'example');

        self::assertSame(['data' => [
            'type' => 'orders',
            'attributes' => ['quantity' => 2],
            'relationships' => ['product' => ['data' => ['type' => 'products', 'id' => 'p-1']]],
        ]], $example);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(): array
    {
        $document = static fn(string $path, string $input): FakeActionMetadata => new FakeActionMetadata($path, ['POST'], ActionScope::Resource, ActionInputMode::Document, inputType: $input);
        $carts = FakeTypeMetadata::resource('carts', [Id::make()->example('c-1')->build()], actions: [
            $document('checkout', 'orders'),
            new FakeActionMetadata('import', ['POST'], ActionScope::Resource, inputType: 'orders', accepts: [new AcceptedBody('application/vnd.api+json'), new AcceptedBody('text/csv')]),
            $document('annotate', 'notes'),
            $document('redeem', 'coupons'),
            $document('issue', 'vouchers'),
        ], operations: [OperationType::FetchOne]);
        $orders = FakeTypeMetadata::resource('orders', [
            Id::make()->example('o-1')->build(),
            Integer::make('quantity')->required()->example(2)->build(),
            Str::make('reference')->readOnly()->example('REF')->build(),
        ], relations: [FakeRelationMetadata::toOne('product', ['products'])], operations: [OperationType::FetchOne]);
        $code = [Id::make()->example('k-1')->build(), Str::make('code')->example('SAVE')->build()];

        return (new OpenApiProjector())->project(new FakeServerMetadata(
            'API',
            '1.0.0',
            [
                $carts,
                $orders,
                FakeTypeMetadata::resource('products', [Id::make()->example('p-1')->build()]),
                FakeTypeMetadata::resource('coupons', $code, allowsClientId: true, operations: [OperationType::FetchOne]),
                FakeTypeMetadata::resource('vouchers', $code, allowsClientId: true, requiresClientId: true, operations: []),
                FakeTypeMetadata::standalone('notes'),
                new FakeTypeMetadata(type: 'pings', uriType: 'pings', hasFields: false, operations: [OperationType::Create, OperationType::Update]),
            ],
            servers: [new Server('https://api.example.com')],
            generatesExamples: true,
        ))->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function bodySchema(array $document, string $path): array
    {
        return $this->arrAt($document, 'paths', $path, 'post', 'requestBody', 'content', 'application/vnd.api+json', 'schema');
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        \ksort($value);

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function arrAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
