<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\ObjBuilder;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Field\UnionBuilder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A value no write can set, a read-only field or anything nested in one, is written only
 * by the server, so its `nullable()` can describe nothing but the rendered value: it
 * admits `null` on read even beside `guaranteed()`. A nested member is read-only for
 * every write its enclosing value is read-only for.
 */
#[CoversClass(SchemaProjector::class)]
#[Group('spec:document-structure')]
final class UnwritableNullableProjectionTest extends TestCase
{
    #[Test]
    public function aReadOnlyAttributeDeclaredNullableAdmitsNullBesideGuaranteed(): void
    {
        $field = Str::make('importKey')->readOnly()->guaranteed()->nullable()->build();

        self::assertSame(['string', 'null'], $this->attribute($field)['type'] ?? null);
        self::assertSame('string', $this->attribute(Str::make('state')->readOnly()->guaranteed()->build())['type'] ?? null);
    }

    #[Test]
    public function aWritableAttributeKeepsItsGuaranteeOverAWritesNull(): void
    {
        // A write may send null, and the server fills a value in.
        $field = Obj::make('settings')->fields(Str::make('status')->guaranteed()->nullable())->build();

        self::assertSame('string', self::at($this->attribute($field), 'properties', 'status', 'type'));
    }

    #[Test]
    public function aNullableMemberOfAReadOnlyObjectAdmitsNull(): void
    {
        $field = Obj::make('changes')->readOnly()->nullable()->fields(
            Decimal::make('from')->guaranteed()->nullable(),
            Decimal::make('to')->guaranteed(),
        )->build();

        $read = $this->attribute($field);
        self::assertSame(['number', 'null'], self::at($read, 'properties', 'from', 'type'));
        self::assertSame('number', self::at($read, 'properties', 'to', 'type'));
    }

    #[Test]
    public function aNullableMemberOfAReadOnlyListOfObjectsAdmitsNull(): void
    {
        $field = ArrayList::make('contracts')->readOnly()->guaranteed()->of(Obj::make('amendment')->fields(
            Decimal::make('from')->guaranteed()->nullable(),
            Decimal::make('to')->guaranteed(),
        ))->build();

        $read = $this->attribute($field);
        self::assertSame(['number', 'null'], self::at($read, 'items', 'properties', 'from', 'type'));
        self::assertSame('number', self::at($read, 'items', 'properties', 'to', 'type'));
    }

    #[Test]
    public function aNullableMemberOfAReadOnlyOneOfAdmitsNull(): void
    {
        $field = OneOf::make('change')->readOnly()->guaranteed()->discriminator('kind')
            ->variant('set', Str::make('value')->guaranteed()->nullable())
            ->variant('change', Str::make('from')->guaranteed()->nullable(), Str::make('to')->guaranteed())
            ->build();

        $variants = self::at($this->attribute($field), 'oneOf');
        self::assertIsArray($variants);
        self::assertSame(['string', 'null'], self::at($variants, 0, 'properties', 'value', 'type'));
        self::assertSame(['string', 'null'], self::at($variants, 1, 'properties', 'from', 'type'));
        self::assertSame('string', self::at($variants, 1, 'properties', 'to', 'type'));
    }

    #[Test]
    public function aNullableMemberOfAReadOnlyMapOrOpenObjectAdmitsNull(): void
    {
        $map = Map::make('totals')->readOnly()->guaranteed()->fields(Decimal::make('net')->guaranteed()->nullable())->build();
        $open = Obj::make('resolution')->readOnly()->guaranteed()->additionalProperties(Str::make('choice')->guaranteed()->nullable())->build();

        self::assertSame(['number', 'null'], self::at($this->attribute($map), 'properties', 'net', 'type'));
        self::assertSame(['string', 'null'], self::at($this->attribute($open), 'additionalProperties', 'type'));
    }

    #[Test]
    public function aNullableUnionMemberOfAReadOnlyObjectGainsItsNull(): void
    {
        $field = Obj::make('column')->readOnly()->guaranteed()->fields(
            Union::make('from')->guaranteed()->nullable()->of(Str::make(), Decimal::make(), Boolean::make()),
        )->build();

        self::assertSame(['string', 'number', 'boolean', 'null'], self::at($this->attribute($field), 'properties', 'from', 'type'));
    }

    #[Test]
    public function aMemberOfAValueOnlyACreateWritesTakesTheCreatesConstraints(): void
    {
        $field = Obj::make('origin')->readOnlyOnUpdate()->guaranteed()->fields(
            Str::make('code')->guaranteed()->onCreate(static function (StrBuilder $f): void {
                $f->maxLength(8);
            })->onUpdate(static function (StrBuilder $f): void {
                $f->nullable();
            }),
        )->build();

        // Only a create sets the member, so its create bound holds on read and the
        // update's null never reaches a response.
        self::assertSame(['type' => 'string', 'maxLength' => 8], self::at($this->attribute($field), 'properties', 'code'));
    }

    #[Test]
    public function aWriteAcceptsNullForANestedNullableMember(): void
    {
        $field = Obj::make('changes')->fields(ArrayList::make('values')->of(Obj::make('value')->fields(
            Str::make('from')->nullable(),
            Str::make('to')->guaranteed()->nullable(),
        )))->build();

        foreach ([RepresentationContext::Create, RepresentationContext::Update] as $context) {
            $item = self::at($this->attribute($field, $context), 'properties', 'values', 'items');
            self::assertSame(['string', 'null'], self::at($item, 'properties', 'from', 'type'), $context->name);
            self::assertSame(['string', 'null'], self::at($item, 'properties', 'to', 'type'), $context->name);
        }
    }

    #[Test]
    public function aNamedShapeUsedByAReadOnlyAndAWritableFieldIsOneComponentAdmittingTheNull(): void
    {
        // The component holds for every use, so it takes the null the read-only use renders.
        $pair = static fn(string $name): ObjBuilder => Obj::make($name)->component('Pair')->fields(Str::make('from')->guaranteed()->nullable(), Str::make('to')->guaranteed());
        $document = $this->project([
            $pair('current')->readOnly()->build(),
            $pair('proposed')->build(),
            ArrayList::make('history')->readOnly()->of($pair('entry'))->build(),
        ]);

        $schemas = self::at($document, 'components', 'schemas');
        self::assertSame(['string', 'null'], self::at($schemas, 'Pair', 'properties', 'from', 'type'));
        self::assertSame('string', self::at($schemas, 'Pair', 'properties', 'to', 'type'));
    }

    #[Test]
    public function aResponseWithNullNestedMembersValidatesAgainstTheProjectedDocument(): void
    {
        $document = $this->project([$this->changes()], 'run-items');

        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);
        $components = \json_decode((string) \json_encode(['components' => $document['components'] ?? []]));
        self::assertInstanceOf(\stdClass::class, $components);
        $resolver->registerRaw($components, 'urn:unwritable-nullable');
        $valid = static fn(array $attributes): bool => $validator->validate(
            \json_decode((string) \json_encode($attributes)),
            '{"$ref": "urn:unwritable-nullable#/components/schemas/RunItemsAttributes"}',
        )->isValid();

        $changes = [
            'values' => [
                ['kind' => 'set', 'placeholder' => 'cap', 'value' => null],
                ['kind' => 'change', 'placeholder' => 'cap', 'from' => null, 'to' => null],
            ],
            'contracts' => [['term' => 'rate', 'from' => null, 'to' => 42.5]],
            'columns' => [['column' => 'priority', 'from' => null, 'to' => null]],
        ];
        self::assertTrue($valid(['changes' => $changes]));
        self::assertTrue($valid(['changes' => null]));

        $changes['contracts'][0]['to'] = null;
        self::assertFalse($valid(['changes' => $changes]), 'A member declared without nullable() still refuses null.');
    }

    private function changes(): FieldInterface
    {
        return Obj::make('changes')->nullable()->readOnly()->fields(
            ArrayList::make('values')->guaranteed()->of(OneOf::make('value')
                ->component('RunItemValueChange')
                ->discriminator('kind')
                ->variant('set', Str::make('placeholder')->guaranteed(), self::placeholderValue('value'))
                ->variant('change', Str::make('placeholder')->guaranteed(), self::placeholderValue('from'), self::placeholderValue('to'))),
            ArrayList::make('contracts')->guaranteed()->of(Obj::make('amendment')->fields(
                Str::make('term')->guaranteed(),
                Decimal::make('from')->guaranteed()->nullable(),
                Decimal::make('to')->guaranteed(),
            )),
            ArrayList::make('columns')->guaranteed()->of(Obj::make('column')->fields(
                Str::make('column')->guaranteed(),
                Union::make('from')->guaranteed()->nullable()->of(Str::make(), Decimal::make(), Boolean::make()),
                Union::make('to')->guaranteed()->nullable()->of(Str::make(), Decimal::make(), Boolean::make()),
            )),
        )->build();
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return array<string, mixed>
     */
    private function project(array $fields, string $type = 'articles'): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata(
            'API',
            '1.0.0',
            [FakeTypeMetadata::resource($type, [Id::make()->build(), ...$fields])],
            servers: [new Server('https://api.example.com')],
        ))->toArray();
    }

    private static function placeholderValue(string $name): UnionBuilder
    {
        return Union::make($name)->component('PlaceholderValue')->nullable()->guaranteed()->of(Str::make(), Decimal::make(), Boolean::make());
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(FieldInterface $field, RepresentationContext $context = RepresentationContext::Read): array
    {
        $schema = self::at((new SchemaProjector())->projectAttributes([$field], $context)->toArray(), 'properties', $field->name());
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
