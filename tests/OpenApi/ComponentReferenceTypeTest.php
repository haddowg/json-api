<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Field\AbstractFieldBuilder;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Priority;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A field-level reference to a hoisted enum or a named shape carries the referenced
 * schema's `type`, so moving a field between its inline form and a component keeps the
 * `type` a diff tool or a generator reads at the field.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(Schema::class)]
#[CoversClass(EnumComponentCollector::class)]
final class ComponentReferenceTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(): AbstractFieldBuilder, string|list<string>|null}>
     */
    public static function kinds(): iterable
    {
        yield 'string enum' => [static fn(): AbstractFieldBuilder => Str::make('v')->enum(Status::class), 'string'];
        yield 'int enum' => [static fn(): AbstractFieldBuilder => Integer::make('v')->enum(Priority::class), 'integer'];
        yield 'named object' => [static fn(): AbstractFieldBuilder => Obj::make('v')->fields(Str::make('name')->required())->component('Named'), 'object'];
        yield 'named map' => [static fn(): AbstractFieldBuilder => Map::make('v')->fields(Str::make('city'))->component('Named'), 'object'];
        yield 'named list' => [static fn(): AbstractFieldBuilder => ArrayList::make('v')->of('string')->maxItems(3)->component('Named'), 'array'];
        yield 'named union' => [static fn(): AbstractFieldBuilder => Union::make('v')->of(Str::make(), Decimal::make(), Boolean::make())->component('Named'), ['string', 'number', 'boolean']];
        yield 'named oneOf' => [static fn(): AbstractFieldBuilder => OneOf::make('v')->discriminator('kind')->variant('a')->variant('b')->component('Named'), null];
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $field
     * @param string|list<string>|null         $type
     */
    #[Test]
    #[DataProvider('kinds')]
    public function aNonNullableReferenceCarriesTheComponentsType(\Closure $field, string|array|null $type): void
    {
        $schema = $this->project($field()->build(), RepresentationContext::Create, new EnumComponentCollector());

        if ($type === null) {
            self::assertArrayNotHasKey('type', $schema);
            self::assertArrayHasKey('$ref', $schema);

            return;
        }
        self::assertSame($type, $schema['type'] ?? null);
        // A typed reference is wrapped, since a $ref's siblings are lost to OAS 3.0 tooling.
        self::assertArrayNotHasKey('$ref', $schema);
        $allOf = $schema['allOf'] ?? null;
        self::assertIsArray($allOf);
        self::assertCount(1, $allOf);
        self::assertIsArray($allOf[0] ?? null);
        self::assertSame(['$ref'], \array_keys($allOf[0]));
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $field
     * @param string|list<string>|null         $type
     */
    #[Test]
    #[DataProvider('kinds')]
    public function aNullableReferenceCarriesTheComponentsTypeWithNull(\Closure $field, string|array|null $type): void
    {
        $schema = $this->project($field()->nullable()->build(), RepresentationContext::Create, new EnumComponentCollector());

        $anyOf = $schema['anyOf'] ?? null;
        self::assertIsArray($anyOf);
        self::assertSame(['type' => 'null'], $anyOf[1] ?? null);
        self::assertIsArray($anyOf[0] ?? null);
        self::assertSame(['$ref'], \array_keys($anyOf[0]), 'the type sits on the union, not on the reference inside it');
        if ($type === null) {
            self::assertArrayNotHasKey('type', $schema);

            return;
        }
        self::assertSame([...(array) $type, 'null'], $schema['type'] ?? null);
    }

    /**
     * @param \Closure(): AbstractFieldBuilder $field
     * @param string|list<string>|null         $type
     */
    #[Test]
    #[DataProvider('kinds')]
    public function theInlineAndComponentFormsOfAFieldCarryTheSameType(\Closure $field, string|array|null $type): void
    {
        self::assertSame($type, $this->project($field()->build(), RepresentationContext::Create)['type'] ?? null);

        foreach ([false, true] as $nullable) {
            foreach (RepresentationContext::cases() as $context) {
                $builder = $field();
                if ($nullable) {
                    $builder->nullable();
                }
                $built = $builder->build();

                self::assertSame(
                    $this->project($built, $context)['type'] ?? null,
                    $this->project($built, $context, new EnumComponentCollector())['type'] ?? null,
                    \sprintf('%s, %s', $context->name, $nullable ? 'nullable' : 'not nullable'),
                );
            }
        }
    }

    #[Test]
    public function anEnumListsItemsAndAUnionsEnumMemberCarryTheEnumsType(): void
    {
        $collector = new EnumComponentCollector();
        $list = ArrayList::make('v')->of('integer')->each(new In([1, 2], enumClass: Priority::class))->build();
        $union = Union::make('w')->of(Str::make()->enum(Status::class), Integer::make())->build();

        self::assertSame(
            ['type' => 'integer', 'allOf' => [['$ref' => '#/components/schemas/Priority']]],
            $this->project($list, RepresentationContext::Create, $collector)['items'] ?? null,
        );
        // A union whose member is an enum projects a oneOf, which has no single type,
        // while the member itself does.
        $schema = $this->project($union, RepresentationContext::Create, $collector);
        self::assertArrayNotHasKey('type', $schema);
        self::assertSame(
            [['type' => 'string', 'allOf' => [['$ref' => '#/components/schemas/Status']]], ['type' => 'integer']],
            $schema['oneOf'] ?? null,
        );
    }

    #[Test]
    public function aRequiredEnumReferenceGainsNoNonEmptyKeyword(): void
    {
        $schema = $this->project(Str::make('v')->enum(Status::class)->required()->build(), RepresentationContext::Create, new EnumComponentCollector());

        self::assertSame(['type' => 'string', 'allOf' => [['$ref' => '#/components/schemas/Status']]], $schema);
    }

    #[Test]
    public function theTypedReferenceValidatesWhatTheBareReferenceDid(): void
    {
        $collector = new EnumComponentCollector();
        $field = Str::make('v')->enum(Status::class)->nullable()->build();
        $value = (new SchemaProjector())->projectField($field, true, $collector);
        $schemas = [];
        foreach ($collector->components() as $name => $component) {
            $schemas[$name] = $component->toJson();
        }
        $root = $value->toJson();
        $root->components = (object) ['schemas' => (object) $schemas];

        $validator = new Validator();
        foreach ([['draft', true], ['archived', true], [null, true], ['nope', false], [1, false]] as [$candidate, $valid]) {
            self::assertSame($valid, $validator->validate($candidate, $root)->isValid(), \var_export($candidate, true));
        }
    }

    /**
     * The one attribute's schema in the given representation.
     *
     * @return array<string, mixed>
     */
    private function project(FieldInterface $field, RepresentationContext $context, ?EnumComponentCollector $collector = null): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], $context, $collector)->toArray();
        self::assertIsArray($attributes['properties'] ?? null);
        $schema = $attributes['properties'][$field->name()] ?? null;
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }
}
