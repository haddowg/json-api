<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnumComponentCollector::class)]
#[CoversClass(SchemaProjector::class)]
#[Group('spec:document-structure')]
final class EnumComponentCollectorTest extends TestCase
{
    #[Test]
    public function registeringTheSameClassTwiceReturnsTheSameNameAndOneComponent(): void
    {
        $collector = new EnumComponentCollector();

        $first = $collector->register(Status::class, Schema::ofType('string')->withEnum(['draft']));
        $second = $collector->register(Status::class, Schema::ofType('string')->withEnum(['published']));

        self::assertSame('Status', $first);
        self::assertSame($first, $second);
        self::assertSame(['Status'], \array_keys($collector->components()));
        // The first registration wins; a repeat does not overwrite.
        self::assertSame(['draft'], $collector->components()['Status']->toArray()['enum']);
    }

    #[Test]
    public function distinctClassesSharingAShortNameAreDisambiguated(): void
    {
        $collector = new EnumComponentCollector();

        // Two different fully-qualified class-strings with the same short name.
        $a = $collector->register('App\\Catalog\\Status', Schema::ofType('string'));
        $b = $collector->register('App\\Billing\\Status', Schema::ofType('integer'));

        self::assertSame('Status', $a);
        self::assertSame('Status2', $b);
        self::assertSame(['Status', 'Status2'], \array_keys($collector->components()));
    }

    #[Test]
    public function anEnumClashingWithAReservedNameIsDisambiguated(): void
    {
        // A backed enum whose short name equals an already-generated component name
        // (`Meta`, `Links`, …) must auto-disambiguate rather than overwrite it.
        $collector = new EnumComponentCollector(['Meta', 'Links']);

        $name = $collector->register('App\\Catalog\\Meta', Schema::ofType('string'));

        self::assertSame('Meta2', $name);
        self::assertSame(['Meta2'], \array_keys($collector->components()));
    }

    #[Test]
    public function aReferenceIsAComponentSchemasPointer(): void
    {
        $collector = new EnumComponentCollector();
        $name = $collector->register(Status::class, Schema::ofType('string'));

        self::assertSame('#/components/schemas/Status', $collector->reference($name)->toArray()['$ref']);
    }

    #[Test]
    public function withoutACollectorTheProjectorEmitsTheEnumInline(): void
    {
        $field = Str::make('status')->enum(Status::class);

        // No collector → backward-compatible inline projection (the Slice-1 behaviour).
        $inline = (new SchemaProjector())->projectField($field)->toArray();

        self::assertSame(['draft', 'published', 'archived'], $inline['enum']);
        self::assertSame(['Draft', 'Published', 'Archived'], $inline['x-enum-varnames']);
        self::assertArrayNotHasKey('$ref', $inline);
    }

    #[Test]
    public function withACollectorTheProjectorHoistsTheEnumAndRefsIt(): void
    {
        $field = Str::make('status')->enum(Status::class);
        $collector = new EnumComponentCollector();

        $schema = (new SchemaProjector())->projectField($field, false, $collector)->toArray();

        self::assertSame('#/components/schemas/Status', $schema['$ref']);
        self::assertArrayNotHasKey('enum', $schema);

        // The hoisted component carries the enum + var-names + table-in-description.
        $component = $collector->components()['Status']->toArray();
        self::assertSame(['draft', 'published', 'archived'], $component['enum']);
        self::assertSame(['Draft', 'Published', 'Archived'], $component['x-enum-varnames']);
        $description = $component['description'];
        self::assertIsString($description);
        self::assertStringContainsString('| Value | Description |', $description);
    }

    #[Test]
    public function aNullableHoistedEnumUnionsTheRefWithNullAndKeepsTheComponentNullFree(): void
    {
        // A nullable backed-enum field is hoisted to a bare `$ref`, which carries no
        // scalar `type` to widen; the OAS-3.1 way to make a referenced schema nullable
        // is to union it with the null type (`anyOf: [{$ref}, {type: null}]`).
        $field = Str::make('status')->enum(Status::class)->nullable();
        $collector = new EnumComponentCollector();

        $schema = (new SchemaProjector())->projectField($field, false, $collector)->toArray();

        // (a) The field schema is the nullable `$ref` union.
        self::assertSame([
            ['$ref' => '#/components/schemas/Status'],
            ['type' => 'null'],
        ], $schema['anyOf']);
        self::assertArrayNotHasKey('$ref', $schema);

        // (b) The hoisted component's `enum` carries the real cases only — no `null`
        // leaks into the shared component from the field's nullability.
        $component = $collector->components()['Status']->toArray();
        self::assertSame(['draft', 'published', 'archived'], $component['enum']);
        self::assertNotContains(null, $component['enum']);
    }
}
