<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Schema::class)]
#[Group('spec:document-structure')]
final class SchemaTest extends TestCase
{
    #[Test]
    public function aBareNodeIsAnEmptyObject(): void
    {
        self::assertSame([], Schema::create()->toArray());
    }

    #[Test]
    public function scalarTypeAndFormatAreEmitted(): void
    {
        $schema = Schema::ofType('string')->withFormat('email')->toArray();

        self::assertSame('string', $schema['type']);
        self::assertSame('email', $schema['format']);
    }

    #[Test]
    public function nullableWidensTypeToAUnion(): void
    {
        self::assertSame(['integer', 'null'], Schema::nullable('integer')->toArray()['type']);
        self::assertSame(['string', 'null'], Schema::ofType('string')->asNullable()->toArray()['type']);
    }

    #[Test]
    public function asNullableIsANoOpWhenTypeIsAlreadyAUnionOrUnset(): void
    {
        self::assertSame([], Schema::create()->asNullable()->toArray());
        self::assertSame(['integer', 'null'], Schema::nullable('integer')->asNullable()->toArray()['type']);
    }

    #[Test]
    public function nestedSchemasRecurseIntoArrays(): void
    {
        $schema = Schema::ofType('object')
            ->withProperty('name', Schema::ofType('string'))
            ->withRequired(['name'])
            ->toArray();

        self::assertSame(['name' => ['type' => 'string']], $schema['properties']);
        self::assertSame(['name'], $schema['required']);
    }

    #[Test]
    public function itemsAndAdditionalPropertiesRecurse(): void
    {
        $list = Schema::ofType('array')->withItems(Schema::ofType('string'))->toArray();
        self::assertSame(['type' => 'string'], $list['items']);

        $hash = Schema::ofType('object')->withAdditionalProperties(Schema::ofType('integer'))->toArray();
        self::assertSame(['type' => 'integer'], $hash['additionalProperties']);

        $closed = Schema::ofType('object')->withAdditionalProperties(false)->toArray();
        self::assertFalse($closed['additionalProperties']);
    }

    #[Test]
    public function combinatorsAndNotRecurse(): void
    {
        $anyOf = Schema::create()->withAnyOf([Schema::ofType('string'), Schema::ofType('integer')])->toArray();
        self::assertSame([['type' => 'string'], ['type' => 'integer']], $anyOf['anyOf']);

        $not = Schema::create()->withNot(Schema::create()->withEnum(['x']))->toArray();
        self::assertSame(['enum' => ['x']], $not['not']);
    }

    #[Test]
    public function vendorExtensionsArePrefixedAndEmittedLast(): void
    {
        $schema = Schema::ofType('string')
            ->withExtension('enum-varnames', ['A', 'B'])
            ->withExtension('x-already-prefixed', true)
            ->toArray();

        self::assertSame(['A', 'B'], $schema['x-enum-varnames']);
        self::assertTrue($schema['x-already-prefixed']);
        self::assertArrayHasKey('type', $schema);
    }

    #[Test]
    public function nullExampleIsHonoured(): void
    {
        $schema = Schema::ofType('string')->withExample(null)->toArray();

        self::assertArrayHasKey('example', $schema);
        self::assertNull($schema['example']);
    }

    #[Test]
    public function keywordsAreEmittedInCanonicalOrder(): void
    {
        $schema = Schema::ofType('string')
            ->withExample('x')
            ->withMaxLength(5)
            ->withDescription('A field')
            ->withMinLength(1)
            ->toArray();

        self::assertSame(['type', 'description', 'minLength', 'maxLength', 'example'], \array_keys($schema));
    }

    #[Test]
    public function jsonSerializeReturnsTheObjectTree(): void
    {
        $schema = Schema::ofType('string')->withMaxLength(3);

        self::assertEquals($schema->toJson(), $schema->jsonSerialize());
        self::assertSame(['type' => 'string', 'maxLength' => 3], (array) $schema->jsonSerialize());
    }

    #[Test]
    public function anEmptyNestedSchemaEncodesAsAJsonObjectNotAnArray(): void
    {
        $json = \json_encode(Schema::ofType('array')->withItems(Schema::create())->toJson(), \JSON_THROW_ON_ERROR);

        self::assertSame('{"type":"array","items":{}}', $json);
    }

    #[Test]
    public function refIsEmittedFirstAndRoundTrips(): void
    {
        $schema = Schema::ref('#/components/schemas/Status')->withDescription('A status.');

        self::assertSame(['$ref', 'description'], \array_keys($schema->toArray()));
        self::assertSame('#/components/schemas/Status', $schema->toArray()['$ref']);
        self::assertSame('#/components/schemas/Status', $schema->get('$ref'));

        $json = \json_encode($schema->toJson(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertSame('{"$ref":"#/components/schemas/Status","description":"A status."}', $json);
    }
}
