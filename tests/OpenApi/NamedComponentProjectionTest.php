<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\ObjBuilder;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Field\UnionBuilder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A field declared with `component()` projects its value's shape once, as a named schema
 * component, and each use is a `$ref` to it carrying what belongs to the use.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(EnumComponentCollector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(GeneratorContract::class)]
final class NamedComponentProjectionTest extends TestCase
{
    #[Test]
    public function aShapeSharedByTwoFieldsIsOneComponent(): void
    {
        $document = $this->project([
            self::placeholder('first')->build(),
            self::placeholder('second')->build(),
        ]);

        self::assertSame(['type' => ['string', 'number', 'boolean'], 'maxLength' => 200], $this->component($document, 'PlaceholderValue'));
        self::assertArrayNotHasKey('PlaceholderValueCreate', $this->components($document));
        self::assertArrayNotHasKey('PlaceholderValueUpdate', $this->components($document));
        foreach (['CreateAttributes', 'UpdateAttributes'] as $suffix) {
            self::assertSame(['$ref' => '#/components/schemas/PlaceholderValue'], $this->property($document, 'Articles' . $suffix, 'first'));
            self::assertSame(['$ref' => '#/components/schemas/PlaceholderValue'], $this->property($document, 'Articles' . $suffix, 'second'));
        }
        // A response can render null for a value no create had to supply.
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/PlaceholderValue'], ['type' => 'null']]],
            $this->property($document, 'ArticlesAttributes', 'first'),
        );
        self::assertSame(['contract' => GeneratorContract::NAMED_COMPONENTS], self::at($document, 'info', 'x-generator'));
    }

    #[Test]
    public function aUseKeepsItsOwnDescriptionDeprecationPresenceAndNull(): void
    {
        $document = $this->project([
            self::placeholder('first')->required()->describedAs('The first value.')->deprecated()->build(),
            self::placeholder('second')->nullable()->build(),
        ]);

        self::assertSame([
            '$ref' => '#/components/schemas/PlaceholderValue',
            'description' => 'The first value.',
            'deprecated' => true,
            'minLength' => 1,
        ], $this->property($document, 'ArticlesCreateAttributes', 'first'));
        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/PlaceholderValue'], ['type' => 'null']]],
            $this->property($document, 'ArticlesUpdateAttributes', 'second'),
        );
        self::assertSame(['type' => ['string', 'number', 'boolean'], 'maxLength' => 200], $this->component($document, 'PlaceholderValue'));
    }

    #[Test]
    public function aUseWithAnExampleWrapsItsReferenceAsEveryReferenceWithAnExampleIs(): void
    {
        $document = $this->project([self::placeholder('first')->example('hello')->build()]);

        self::assertSame(
            ['allOf' => [['$ref' => '#/components/schemas/PlaceholderValue']], 'example' => 'hello'],
            $this->property($document, 'ArticlesCreateAttributes', 'first'),
        );
    }

    #[Test]
    public function aShapeThatDiffersOnAWriteSplitsIntoCreateAndUpdateComponents(): void
    {
        $document = $this->project([self::reviewer('reviewedBy')->build(), self::reviewer('approvedBy')->build()]);

        $read = $this->component($document, 'Reviewer');
        self::assertSame(['name', 'reviewedAt'], $read['required']);
        // A read-only member leaves both writes, which are emitted even though they
        // differ only in what a create requires.
        self::assertSame(['name'], \array_keys(self::properties($this->component($document, 'ReviewerCreate'))));
        self::assertSame(['name'], $this->component($document, 'ReviewerCreate')['required']);
        self::assertSame(['name'], \array_keys(self::properties($this->component($document, 'ReviewerUpdate'))));
        self::assertArrayNotHasKey('required', $this->component($document, 'ReviewerUpdate'));

        self::assertSame(['$ref' => '#/components/schemas/ReviewerCreate'], $this->property($document, 'ArticlesCreateAttributes', 'approvedBy'));
        self::assertSame(['$ref' => '#/components/schemas/ReviewerUpdate'], $this->property($document, 'ArticlesUpdateAttributes', 'approvedBy'));
    }

    #[Test]
    public function aWriteIdenticalToTheReadShareTheBareName(): void
    {
        // A create requires what a response always renders; an update merges, so requires nothing.
        $field = Map::make('address')->fields(Str::make('city')->required())->component('Address')->build();
        $document = $this->project([$field]);

        self::assertArrayNotHasKey('AddressCreate', $this->components($document));
        self::assertArrayHasKey('AddressUpdate', $this->components($document));
        self::assertSame(['$ref' => '#/components/schemas/Address'], $this->property($document, 'ArticlesCreateAttributes', 'address'));
        self::assertSame(['$ref' => '#/components/schemas/AddressUpdate'], $this->property($document, 'ArticlesUpdateAttributes', 'address'));
    }

    #[Test]
    public function aComponentOnlyAReadUsesHasNoWriteComponents(): void
    {
        $document = $this->project([self::reviewer('reviewedBy')->readOnly()->build()]);

        self::assertArrayHasKey('Reviewer', $this->components($document));
        self::assertArrayNotHasKey('ReviewerCreate', $this->components($document));
        self::assertArrayNotHasKey('ReviewerUpdate', $this->components($document));
    }

    #[Test]
    public function aListElementCanBeANamedShape(): void
    {
        $document = $this->project([
            ArrayList::make('values')->of(Union::of(Str::make(), Decimal::make(), Boolean::make())->component('Scalar'))->build(),
            ArrayList::make('entries')->of(Obj::make('entry')->fields(Str::make('key')->required())->component('Entry'))->build(),
            Union::make('one')->of(Str::make(), Decimal::make(), Boolean::make())->component('Scalar')->build(),
        ]);

        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Scalar']], $this->property($document, 'ArticlesCreateAttributes', 'values'));
        self::assertSame(['$ref' => '#/components/schemas/Scalar'], $this->property($document, 'ArticlesCreateAttributes', 'one'));
        self::assertSame(['type' => ['string', 'number', 'boolean']], $this->component($document, 'Scalar'));
        // A list's element is written whole, so its update requires what an update requires.
        self::assertSame(['key'], $this->component($document, 'Entry')['required']);
        self::assertSame(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Entry']], $this->property($document, 'ArticlesUpdateAttributes', 'entries'));
    }

    #[Test]
    public function namedShapesNestAndOneOfAndListsCanBeNamed(): void
    {
        $point = static fn(): ObjBuilder => Obj::make('point')->fields(Integer::make('x')->required(), Integer::make('y')->required())->component('Point');
        $document = $this->project([
            OneOf::make('shape')
                ->variant('circle', $point(), Integer::make('radius')->required())
                ->variant('line', $point(), Obj::make('to')->fields(Integer::make('x')->required(), Integer::make('y')->required())->component('Point')->required())
                ->component('Shape')
                ->build(),
            ArrayList::make('tags')->of('string')->maxItems(5)->component('TagList')->build(),
        ]);

        self::assertSame(['$ref' => '#/components/schemas/ShapeCreate'], $this->property($document, 'ArticlesCreateAttributes', 'shape'));
        self::assertSame(['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string']], $this->component($document, 'TagList'));
        $line = self::at($this->component($document, 'ShapeCreate'), 'oneOf', 1, 'properties');
        self::assertSame(['$ref' => '#/components/schemas/Point', 'minProperties' => 1], self::at($line, 'to'));
        self::assertSame(['$ref' => '#/components/schemas/Point'], self::at($line, 'point'));
        // Inside a union written whole an update requires what a response renders.
        self::assertSame(['x', 'y'], $this->component($document, 'Point')['required']);
        self::assertArrayNotHasKey('PointUpdate', $this->components($document));
    }

    #[Test]
    public function twoShapesUnderOneNameFail(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Fields "first" and "second" both name the schema component "PlaceholderValue" but project different shapes for a response.');

        $this->project([
            self::placeholder('first')->build(),
            Union::make('second')->of(Str::make(), Integer::make())->component('PlaceholderValue')->build(),
        ]);
    }

    #[Test]
    public function anObjectWrittenWholeAndMergedHasTwoUpdateShapes(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('project different shapes for an update');

        $entry = static fn(): ObjBuilder => Obj::make('entry')->fields(Str::make('key')->required())->component('Entry');
        $this->project([
            $entry()->build(),
            ArrayList::make('entries')->of($entry())->build(),
        ]);
    }

    #[Test]
    public function anExtractedUseSharesTheDeclaredShapesComponent(): void
    {
        $document = $this->project([
            self::reviewer('reviewedBy')->build(),
            self::reviewer('approvedBy')->extractUsing(static fn(mixed $model): array => ['name' => 'Ada'])->build(),
        ]);

        self::assertSame(['name', 'reviewedAt'], $this->component($document, 'Reviewer')['required']);
        // The hook's non-null return type guarantees a value, so the use admits no null.
        self::assertSame(['$ref' => '#/components/schemas/Reviewer'], $this->property($document, 'ArticlesAttributes', 'approvedBy'));
    }

    #[Test]
    public function aSerializedUseRendersItsOwnShapeSoItCannotShareTheName(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Fields "reviewedBy" and "approvedBy" both name the schema component "Reviewer" but project different shapes for a response.');

        $this->project([
            self::reviewer('reviewedBy')->build(),
            self::reviewer('approvedBy')->serializeUsing(static fn(mixed $model): array => ['name' => 'Ada'])->build(),
        ]);
    }

    #[Test]
    public function aNameAGeneratedComponentTakesFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Field "first" names the schema component "ArticlesResource", which the document already generates.');

        $this->project([Union::make('first')->of(Str::make(), Integer::make())->component('ArticlesResource')->build()]);
    }

    #[Test]
    public function aNameAHoistedEnumTakesFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('names the schema component "Status"');

        $this->project([
            Str::make('status')->enum(Status::class)->build(),
            Union::make('first')->of(Str::make(), Integer::make())->component('Status')->build(),
        ]);
    }

    #[Test]
    public function aNameAnotherShapeDerivesFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('which the component "Reviewer" already uses');

        $this->project([
            self::reviewer('reviewedBy')->build(),
            Union::make('other')->of(Str::make(), Integer::make())->component('ReviewerCreate')->build(),
        ]);
    }

    #[Test]
    public function aNameMustBeAComponentKey(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a component name takes only letters, digits');

        Union::make('first')->of(Str::make())->component('Placeholder Value');
    }

    #[Test]
    public function aStandaloneProjectionInlinesTheShape(): void
    {
        $named = self::reviewer('reviewedBy')->required()->nullable()->describedAs('Who.')->build();
        $plain = Obj::make('reviewedBy')->fields(Str::make('name')->required(), DateTime::make('reviewedAt')->readOnly())->required()->nullable()->describedAs('Who.')->build();
        $projector = new SchemaProjector();

        foreach ([RepresentationContext::Read, RepresentationContext::Create, RepresentationContext::Update] as $context) {
            self::assertSame(
                $projector->projectAttributes([$plain], $context)->toArray(),
                $projector->projectAttributes([$named], $context)->toArray(),
            );
        }
    }

    private static function placeholder(string $name): UnionBuilder
    {
        return Union::make($name)->of(Str::make()->maxLength(200), Decimal::make(), Boolean::make())->component('PlaceholderValue');
    }

    private static function reviewer(string $name): ObjBuilder
    {
        return Obj::make($name)->fields(Str::make('name')->required(), DateTime::make('reviewedAt')->readOnly())->component('Reviewer');
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return array<string, mixed>
     */
    private function project(array $fields): array
    {
        return (new OpenApiProjector())->project(new FakeServerMetadata('API', '1.0.0', [FakeTypeMetadata::resource('articles', $fields)], servers: [new Server('https://api.example.com')]))->toArray();
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function components(array $document): array
    {
        $schemas = self::at($document, 'components', 'schemas');
        self::assertIsArray($schemas);

        /** @var array<string, mixed> $schemas */
        return $schemas;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function component(array $document, string $name): array
    {
        $schema = $this->components($document)[$name] ?? null;
        self::assertIsArray($schema, "No component {$name}.");

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function property(array $document, string $component, string $name): array
    {
        $schema = self::properties($this->component($document, $component))[$name] ?? null;
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        self::assertIsArray($properties);

        /** @var array<string, mixed> $properties */
        return $properties;
    }

    private static function at(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
