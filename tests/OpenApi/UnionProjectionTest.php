<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Validation\SchemaCompiler;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `Union` projects one schema whose `type` lists its members' JSON types with their
 * type-scoped keywords merged in, or a `oneOf` of the members' schemas when a member carries
 * a keyword that is not scoped to its type.
 */
#[CoversClass(SchemaProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(GeneratorContract::class)]
#[CoversClass(Union::class)]
final class UnionProjectionTest extends TestCase
{
    #[Test]
    public function aUnionProjectsOneSchemaListingItsMembersTypes(): void
    {
        $write = [
            'type' => ['string', 'number', 'boolean', 'array', 'null'],
            'maxLength' => 20,
            'maxItems' => 5,
            'items' => ['type' => ['string', 'number', 'boolean']],
        ];

        self::assertSame($write, $this->attribute($this->value(), RepresentationContext::Create));
        self::assertSame($write, $this->attribute($this->value(), RepresentationContext::Update));
        self::assertSame($write, $this->attribute($this->value(), RepresentationContext::Read));
    }

    #[Test]
    public function aUnionThatIsNotNullableAdmitsNoNullOnAWrite(): void
    {
        $field = Union::make('cap')->of(Integer::make()->min(0)->max(10)->multipleOf(2), Str::make()->pattern('^[a-z]+$')->uuid())->required()->build();

        self::assertSame([
            'type' => ['string', 'integer'],
            'format' => 'uuid',
            'minimum' => 0,
            'maximum' => 10,
            'multipleOf' => 2,
            'minLength' => 1,
            'pattern' => '^[a-z]+$',
        ], $this->attribute($field, RepresentationContext::Create));
    }

    #[Test]
    public function aRequiredUnionRefusesOnlyAnEmptyStringOrList(): void
    {
        $compact = Union::make('v')->of(Str::make(), Integer::make(), Boolean::make(), ArrayList::make()->of('string'))->required()->build();
        $branched = Union::make('v')->of(Str::make()->in(['a', 'b']), Integer::make(), ArrayList::make()->of('string')->minItems(2))->required()->build();

        self::assertSame([
            'type' => ['string', 'integer', 'boolean', 'array'],
            'minLength' => 1,
            'minItems' => 1,
            'items' => ['type' => 'string'],
        ], $this->attribute($compact, RepresentationContext::Create));
        self::assertSame(['oneOf' => [
            ['type' => 'string', 'enum' => ['a', 'b'], 'minLength' => 1],
            ['type' => 'integer'],
            ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'string']],
        ]], $this->attribute($branched, RepresentationContext::Create));
    }

    #[Test]
    public function aMemberWithAnEnumProjectsAOneOfWithNullAsItsOwnBranch(): void
    {
        $field = Union::make('threshold')->of(Str::make()->in(['auto', 'off']), Integer::make()->min(0))->nullable()->build();

        self::assertSame([
            'oneOf' => [
                ['type' => 'string', 'enum' => ['auto', 'off']],
                ['type' => 'integer', 'minimum' => 0],
                ['type' => 'null'],
            ],
        ], $this->attribute($field, RepresentationContext::Create));
    }

    #[Test]
    public function aMemberWithAnotherUnscopedKeywordAlsoProjectsAOneOf(): void
    {
        $described = Union::make('v')->of(Str::make()->describedAs('A label.'), Boolean::make())->build();
        $temporal = Union::make('v')->of(DateTime::make()->format('d/m/Y'), Boolean::make())->build();
        $hoisted = Union::make('v')->of(Str::make()->enum(UnionProjectionStatus::class), Boolean::make())->build();

        self::assertSame([['type' => 'string', 'description' => 'A label.'], ['type' => 'boolean']], self::at($this->attribute($described, RepresentationContext::Create), 'oneOf'));
        self::assertIsString(self::at($this->attribute($temporal, RepresentationContext::Create), 'oneOf', 0, 'description'));

        $collector = new EnumComponentCollector();
        $schema = (new SchemaProjector())->projectField($hoisted, true, $collector)->toArray();
        self::assertSame(['oneOf' => [['$ref' => '#/components/schemas/UnionProjectionStatus'], ['type' => 'boolean']]], $schema);
    }

    #[Test]
    public function aListOfUnionsProjectsTheElementUnionAsItsItems(): void
    {
        $field = ArrayList::make('values')->of(Union::of(Str::make()->maxLength(3), Integer::make()))->maxItems(4)->build();

        self::assertSame([
            'type' => 'array',
            'maxItems' => 4,
            'items' => ['type' => ['string', 'integer'], 'maxLength' => 3],
        ], $this->attribute($field, RepresentationContext::Create));
    }

    #[Test]
    public function aUnionKeepsItsOwnDescriptionAndExample(): void
    {
        $field = Union::make('v')->of(Str::make(), Decimal::make())->describedAs('A binding value.')->example(1.5)->build();

        self::assertSame(['type' => ['string', 'number'], 'description' => 'A binding value.', 'examples' => [1.5]], $this->attribute($field, RepresentationContext::Create));
    }

    #[Test]
    public function aUnionInsideAnObjectProjectsTheSame(): void
    {
        $field = Obj::make('settings')->fields(Union::make('cap')->of(Str::make(), Decimal::make()))->build();

        self::assertSame(['type' => ['string', 'number']], self::at($this->attribute($field, RepresentationContext::Create), 'properties', 'cap'));
    }

    #[Test]
    public function theCompactSchemaAcceptsExactlyWhatTheMembersAccept(): void
    {
        $schema = (new SchemaProjector())->projectField($this->value(), true)->toJson();

        foreach (['abc', 1, 1.5, true, [], ['a', 2, false], null] as $value) {
            self::assertTrue($this->validates($schema, $value), \json_encode($value, \JSON_THROW_ON_ERROR));
        }
        foreach ([\str_repeat('x', 21), new \stdClass(), [['nested']], [1, 2, 3, 4, 5, 6], [null]] as $value) {
            self::assertFalse($this->validates($schema, $value), \json_encode($value, \JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function theOneOfSchemaAcceptsExactlyWhatTheMembersAccept(): void
    {
        $field = Union::make('threshold')->of(Str::make()->in(['auto', 'off']), Integer::make()->min(0))->nullable()->build();
        $schema = (new SchemaProjector())->projectField($field, true)->toJson();

        foreach (['auto', 0, 12, null] as $value) {
            self::assertTrue($this->validates($schema, $value), \json_encode($value, \JSON_THROW_ON_ERROR));
        }
        foreach (['on', -1, 1.5, true] as $value) {
            self::assertFalse($this->validates($schema, $value), \json_encode($value, \JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function theBodyValidationSchemaDescribesTheUnionAsTheProjectionDoes(): void
    {
        $value = $this->value();
        $list = ArrayList::make('values')->of(Union::of(Str::make(), Integer::make()))->build();
        $resource = new class ([$value, $list]) extends AbstractResource {
            public static string $type = 'bindings';

            /**
             * @param list<FieldInterface> $declared
             */
            public function __construct(private readonly array $declared) {}

            public function fields(): array
            {
                return [Id::make(), ...$this->declared];
            }
        };

        $compiled = \json_decode((string) \json_encode((new SchemaCompiler())->compile($resource, true)), true);

        self::assertSame($this->attribute($value, RepresentationContext::Create), self::at($compiled, 'properties', 'data', 'properties', 'attributes', 'properties', 'value'));
        self::assertSame($this->attribute($list, RepresentationContext::Create), self::at($compiled, 'properties', 'data', 'properties', 'attributes', 'properties', 'values'));
    }

    #[Test]
    public function aDocumentWithAUnionCarriesItsScopedContract(): void
    {
        $plain = $this->project([Id::make()->build(), Str::make('title')->build()]);
        $direct = $this->project([Id::make()->build(), $this->value()]);
        $listed = $this->project([Id::make()->build(), ArrayList::make('values')->of(Union::of(Str::make()))->build()]);
        $nested = $this->project([Id::make()->build(), Obj::make('meta')->additionalProperties(Union::make('*')->of(Str::make(), Decimal::make()))->build()]);
        $hidden = $this->project([Id::make()->build(), Union::make('v')->of(Str::make())->hidden()->build()]);

        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($plain, 'info', 'x-generator'));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::UNIONS)], self::at($direct, 'info', 'x-generator'));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::UNIONS)], self::at($listed, 'info', 'x-generator'));
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::UNIONS, GeneratorContract::OPEN_OBJECTS)], self::at($nested, 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($hidden, 'info', 'x-generator'));
    }

    #[Test]
    public function anOperationExampleTakesTheFirstMemberDeclaringOne(): void
    {
        $document = $this->project([
            Id::make()->example('a-1')->build(),
            Union::make('value')->of(Str::make(), Decimal::make()->example(2.5))->build(),
            Union::make('override')->of(Str::make()->example('x'), Decimal::make())->example(4.0)->build(),
            ArrayList::make('values')->of(Union::of(Boolean::make(), Str::make()->example('x')))->build(),
        ]);

        self::assertSame(
            ['value' => 2.5, 'override' => 4.0, 'values' => ['x']],
            self::at($document, 'paths', '/articles', 'post', 'requestBody', 'content', 'application/vnd.api+json', 'example', 'data', 'attributes'),
        );
    }

    private function validates(\stdClass $schema, mixed $value): bool
    {
        return (new Validator())->validate(\json_decode((string) \json_encode($value)), $schema)->isValid();
    }

    private function value(): Union
    {
        return Union::make('value')->of(
            Str::make()->maxLength(20),
            Decimal::make(),
            Boolean::make(),
            ArrayList::make()->maxItems(5)->of(Union::of(Str::make(), Decimal::make(), Boolean::make())),
        )->nullable()->build();
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

enum UnionProjectionStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}
