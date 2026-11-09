<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An `Obj` declaring `additionalProperties()` projects that field's schema as its
 * `additionalProperties`, beside its declared `properties`, and bounds its member count
 * with `minProperties` / `maxProperties`.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(GeneratorContract::class)]
#[CoversClass(Obj::class)]
final class OpenObjectProjectionTest extends TestCase
{
    #[Test]
    public function aListOfOpenRowsProjectsTheUnwrappedShape(): void
    {
        $field = ArrayList::make('rows')
            ->of(Obj::make('row')->additionalProperties(Str::make('*')->maxLength(200))->maxProperties(50))
            ->maxItems(1000)
            ->build();

        $write = [
            'type' => 'array',
            'maxItems' => 1000,
            'items' => [
                'type' => 'object',
                'maxProperties' => 50,
                'additionalProperties' => ['type' => 'string', 'maxLength' => 200],
            ],
        ];
        self::assertSame($write, $this->attribute($field, RepresentationContext::Create));
        self::assertSame($write, $this->attribute($field, RepresentationContext::Update));
        self::assertSame([
            'type' => ['array', 'null'],
            'maxItems' => 1000,
            'items' => [
                'type' => 'object',
                'maxProperties' => 50,
                'additionalProperties' => ['type' => ['string', 'null'], 'maxLength' => 200],
            ],
        ], $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function declaredChildrenKeepTheirOwnSchemasBesideTheMembers(): void
    {
        $field = Obj::make('row')
            ->fields(Str::make('Key')->required(), Integer::make('Count'))
            ->additionalProperties(Str::make('*')->nullable())
            ->minProperties(1)
            ->build();

        self::assertSame([
            'type' => 'object',
            'minProperties' => 1,
            'properties' => [
                'Key' => ['type' => 'string', 'minLength' => 1],
                'Count' => ['type' => 'integer'],
            ],
            'required' => ['Key'],
            'additionalProperties' => ['type' => ['string', 'null']],
        ], $this->attribute($field, RepresentationContext::Create));
    }

    #[Test]
    public function anOpenObjectNestedAnywhereProjectsTheSame(): void
    {
        $open = static fn(): Obj => Obj::make('labels')->additionalProperties(Str::make('*'))->build();
        $schema = ['type' => 'object', 'additionalProperties' => ['type' => 'string']];

        self::assertSame($schema, self::at($this->attribute(Obj::make('meta')->fields($open())->build(), RepresentationContext::Create), 'properties', 'labels'));
        self::assertSame($schema, self::at($this->attribute(Map::make('meta')->fields($open())->build(), RepresentationContext::Create), 'properties', 'labels'));
        $union = $this->attribute(OneOf::make('block')->discriminator('kind')->variant('tagged', $open())->build(), RepresentationContext::Create);
        self::assertSame($schema, self::at($union, 'oneOf', 0, 'properties', 'labels'));
    }

    #[Test]
    public function aReadOnlyMemberFieldLeavesTheObjectClosedOnAWrite(): void
    {
        $field = Obj::make('metrics')->additionalProperties(Integer::make('*')->readOnly())->build();

        self::assertSame(['type' => 'object', 'additionalProperties' => false], $this->attribute($field, RepresentationContext::Create));
        self::assertSame(['type' => 'object', 'additionalProperties' => false], $this->attribute($field, RepresentationContext::Update));
        self::assertSame(['type' => ['object', 'null'], 'additionalProperties' => ['type' => ['integer', 'null']]], $this->attribute($field, RepresentationContext::Read));
        // A request validator ignores what it may not write, so it admits the members.
        self::assertSame(['type' => 'object', 'additionalProperties' => ['type' => 'integer']], self::admitted($field));
    }

    #[Test]
    public function aWriteOnlyMemberFieldLeavesTheObjectClosedOnARead(): void
    {
        $field = Obj::make('secrets')->additionalProperties(Str::make('*')->writeOnly())->build();

        self::assertSame(['type' => 'object', 'additionalProperties' => ['type' => 'string']], $this->attribute($field, RepresentationContext::Create));
        self::assertSame(['type' => ['object', 'null'], 'additionalProperties' => false], $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function aHookShapingTheObjectLeavesItOpenAsBefore(): void
    {
        $field = Obj::make('row')
            ->additionalProperties(Str::make('*'))
            ->serializeUsing(static fn(mixed $model): array => [])
            ->build();

        self::assertArrayNotHasKey('additionalProperties', $this->attribute($field, RepresentationContext::Read));
    }

    #[Test]
    public function aDocumentWithAnOpenObjectCarriesItsScopedContract(): void
    {
        $closed = $this->project([Id::make()->build(), Obj::make('meta')->fields(Str::make('a'))->maxProperties(3)->build()]);
        $open = $this->project([Id::make()->build(), Obj::make('meta')->additionalProperties(Str::make('*'))->build()]);
        $nested = $this->project([Id::make()->build(), ArrayList::make('rows')->of(Obj::make('row')->additionalProperties(Str::make('*')))->build()]);
        $hidden = $this->project([Id::make()->build(), Obj::make('meta')->additionalProperties(Str::make('*'))->hidden()->build()]);

        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($closed, 'info', 'x-generator'));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::OPEN_OBJECTS)], self::at($open, 'info', 'x-generator'));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::OPEN_OBJECTS)], self::at($nested, 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($hidden, 'info', 'x-generator'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function admitted(FieldInterface $field): array
    {
        $projector = new SchemaProjector();

        return $projector->admittingReadOnly(static fn(): array => $projector->projectField($field, true)->toArray());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function attribute(FieldInterface $field, RepresentationContext $context): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], $context)->toArray();
        $schema = self::at($attributes, 'properties', $field->name());
        self::assertIsArray($schema);

        return $schema;
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return array<array-key, mixed>
     */
    private function project(array $fields): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata('API', '1.0.0', [FakeTypeMetadata::resource('articles', $fields)], servers: [new Server('https://api.example.com')]))->toArray();
    }

    private static function at(mixed $value, string|int ...$keys): mixed
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
