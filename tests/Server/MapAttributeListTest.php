<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\Middleware\ErrorHandlerMiddleware;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A map-shaped attribute (`ArrayHash`, `Map`, `Obj`, `OneOf`) is a JSON object, so a
 * non-empty JSON array sent for one answers 422 `ATTRIBUTE_VALUE_INVALID` at the
 * attribute's pointer instead of being stored and served back as `{"0": …}`. Driven
 * through {@see Server::handle()} with a raw body, so the request is decoded the way a
 * real one is.
 */
#[CoversClass(ArrayHash::class)]
#[CoversClass(Map::class)]
#[CoversClass(Obj::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(JsonObject::class)]
final class MapAttributeListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function listsSentForAMap(): iterable
    {
        yield 'ArrayHash' => ['"preferences":[1,2]', '/data/attributes/preferences', 'preferences'];
        yield 'Map' => ['"address":[1,2]', '/data/attributes/address', 'address'];
        yield 'Obj' => ['"settings":[1,2]', '/data/attributes/settings', 'settings'];
        yield 'OneOf' => ['"block":[1,2]', '/data/attributes/block', 'block'];
        yield 'ArrayHash inside an Obj' => ['"settings":{"tags":["a"]}', '/data/attributes/settings/tags', 'settings/tags'];
        yield 'ArrayHash inside a Map' => ['"address":{"extra":["a"]}', '/data/attributes/address/extra', 'address/extra'];
        yield 'ArrayHash inside a OneOf arm' => ['"block":{"type":"image","attrs":[1]}', '/data/attributes/block/attrs', 'block/attrs'];
        yield 'Obj inside an Obj' => ['"settings":{"inner":{"labels":[1]}}', '/data/attributes/settings/inner/labels', 'settings/inner/labels'];
        yield 'DateTime inside an Obj' => ['"settings":{"since":"banana"}', '/data/attributes/settings/since', 'settings/since'];
    }

    #[Test]
    #[DataProvider('listsSentForAMap')]
    public function aListAnswers422AtTheAttributesPointer(string $attributes, string $pointer, string $attribute): void
    {
        [$status, $document] = $this->post($attributes);

        self::assertSame(422, $status, (string) \json_encode($document));
        self::assertIsArray($document['errors'] ?? null);
        self::assertCount(1, $document['errors']);
        $error = $document['errors'][0];
        self::assertIsArray($error);
        self::assertSame('ATTRIBUTE_VALUE_INVALID', $error['code'] ?? null);
        self::assertSame(['pointer' => $pointer], $error['source'] ?? null);
        if (!\str_contains($attributes, 'banana')) {
            self::assertSame(
                \sprintf('The value for attribute "%s" is invalid: expected an object, got a list', $attribute),
                $error['detail'] ?? null,
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'empty objects' => ['"preferences":{},"address":{},"settings":{},"block":{}'];
        yield 'non-empty objects' => ['"preferences":{"theme":"dark"},"address":{"postcode":"N1"},"settings":{"label":"home","tags":{"a":1}},"block":{"type":"image","src":"x.png","attrs":{"w":1}}'];
        // `[]` decodes to the same empty PHP array as `{}`, so hydration cannot reject it.
        yield 'empty lists' => ['"preferences":[],"address":[],"settings":[],"block":[]'];
        yield 'nulls for nullable fields' => ['"preferences":null,"settings":null,"block":null'];
        yield 'absent fields' => ['"title":"untouched"'];
    }

    #[Test]
    #[DataProvider('acceptedValues')]
    public function anObjectAnEmptyArrayOrNullHydrates(string $attributes): void
    {
        [$status, $document] = $this->post($attributes);

        self::assertSame(201, $status, (string) \json_encode($document));
    }

    #[Test]
    public function aNullForANullableMapIsStored(): void
    {
        [$status, $document] = $this->post('"preferences":null,"settings":null,"block":null');

        self::assertSame(201, $status);
        self::assertIsArray($document['data'] ?? null);
        self::assertIsArray($document['data']['attributes'] ?? null);
        self::assertNull($document['data']['attributes']['preferences']);
        self::assertNull($document['data']['attributes']['settings']);
        self::assertNull($document['data']['attributes']['block']);
    }

    #[Test]
    public function anAbsentMapKeepsItsStoredValue(): void
    {
        [$status, $document] = $this->post('"title":"t"');

        self::assertSame(201, $status);
        self::assertIsArray($document['data'] ?? null);
        self::assertIsArray($document['data']['attributes'] ?? null);
        self::assertSame(['theme' => 'light'], $document['data']['attributes']['preferences']);
    }

    /**
     * @return iterable<string, array{\Closure(): \haddowg\JsonApi\Resource\Field\FieldInterface}>
     */
    public static function mapFields(): iterable
    {
        yield 'ArrayHash' => [static fn() => ArrayHash::make('tags')->build()];
        yield 'Map' => [static fn() => Map::make('tags')->fields(Str::make('a'))->build()];
        yield 'Obj' => [static fn() => Obj::make('tags')->fields(Str::make('a'))->build()];
        yield 'OneOf' => [static fn() => OneOf::make('tags')->variant('x', Str::make('a'))->build()];
    }

    /**
     * A pivot field is written by the adapter through {@see \haddowg\JsonApi\Resource\Field\FieldInterface::castWireValue()},
     * so the cast refuses a list there too.
     *
     * @param \Closure(): \haddowg\JsonApi\Resource\Field\FieldInterface $field
     */
    #[Test]
    #[DataProvider('mapFields')]
    public function thePivotCastRejectsAList(\Closure $field): void
    {
        $relation = BelongsToMany::make('tags', 'tags')->fields($field())->build();
        $pivot = $relation->pivotField('tags');
        self::assertNotNull($pivot);

        self::assertSame(['a' => 1], $pivot->castWireValue(['a' => 1]));
        self::assertSame([], $pivot->castWireValue([]));
        self::assertNull($pivot->castWireValue(null));

        $this->expectException(AttributeValueInvalid::class);
        $pivot->castWireValue([1, 2]);
    }

    /**
     * @return array{int, array<array-key, mixed>}
     */
    private function post(string $attributes): array
    {
        $psr17 = new Psr17Factory();

        $handler = new class implements OperationHandlerInterface {
            public function handle(JsonApiOperationInterface $operation): DataResponse
            {
                \assert($operation instanceof CreateResourceOperation);
                $resource = $operation->context()->server->serializerFor('widgets');
                \assert($resource instanceof MapListWidgetResource);

                $widget = $resource->hydrate($operation->body(), (object) [
                    'id' => '1',
                    'title' => null,
                    'preferences' => ['theme' => 'light'],
                    'postcode' => null,
                    'extra' => null,
                    'settings' => null,
                    'block' => null,
                ]);

                return DataResponse::fromResource($widget, $resource)->withStatus(201);
            }
        };

        $base = Server::make()
            ->register(MapListWidgetResource::class)
            ->withPsr17($psr17, $psr17)
            ->withHandler($handler);

        $response = $base->withMiddleware([new ErrorHandlerMiddleware($base)])->handle(
            (new ServerRequest('POST', '/widgets', ['Content-Type' => 'application/vnd.api+json']))
                ->withBody($psr17->createStream('{"data":{"type":"widgets","attributes":{' . $attributes . '}}}'))
                ->withAttribute(Target::class, new Target('widgets')),
        );

        $document = \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return [$response->getStatusCode(), $document];
    }
}

final class MapListWidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->nullable(),
            ArrayHash::make('preferences')->nullable(),
            Map::make('address')->fields(Str::make('postcode'), ArrayHash::make('extra')),
            Obj::make('settings')->nullable()->fields(
                Str::make('label'),
                ArrayHash::make('tags'),
                DateTime::make('since'),
                Obj::make('inner')->fields(ArrayHash::make('labels')),
            ),
            OneOf::make('block')->nullable()
                ->variant('heading', Str::make('text'))
                ->variant('image', Str::make('src'), ArrayHash::make('attrs')),
        ];
    }
}
