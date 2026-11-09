<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Uuid;
use haddowg\JsonApi\Resource\ValueTypeMismatch;
use haddowg\JsonApi\Resource\ValueTypes;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A written value must have the JSON type its write schema documents, at any depth, and
 * may be `null` only where its field is nullable.
 */
#[CoversClass(ValueTypes::class)]
#[CoversClass(ValueTypeMismatch::class)]
final class ValueTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{FieldInterface, string}>
     */
    public static function fieldTypes(): iterable
    {
        yield 'Str' => [Str::make('s')->build(), 'string'];
        yield 'Email' => [Email::make('e')->build(), 'string'];
        yield 'Uuid' => [Uuid::make('u')->build(), 'string'];
        yield 'string enum' => [Str::make('k')->enum(ValueTypesKind::class)->build(), 'string'];
        yield 'Integer' => [Integer::make('i')->build(), 'integer'];
        yield 'integer enum' => [Integer::make('p')->enum(ValueTypesPriority::class)->build(), 'integer'];
        yield 'Decimal' => [Decimal::make('d')->build(), 'number'];
        yield 'Boolean' => [Boolean::make('b')->build(), 'boolean'];
        yield 'Date' => [Date::make('day')->build(), 'string'];
        yield 'DateTime' => [DateTime::make('at')->build(), 'string'];
        yield 'Time' => [Time::make('clock')->build(), 'string'];
        yield 'Id' => [Id::make()->build(), 'string'];
        yield 'ArrayList' => [ArrayList::make('tags')->build(), 'array'];
        yield 'ArrayHash' => [ArrayHash::make('hash')->build(), 'object'];
        yield 'Map' => [Map::make('map')->fields(Str::make('a'))->build(), 'object'];
        yield 'Obj' => [Obj::make('obj')->fields(Str::make('a'))->build(), 'object'];
        yield 'OneOf' => [OneOf::make('one')->discriminator('kind')->variant('a', Str::make('x'))->build(), 'object'];
    }

    #[Test]
    #[DataProvider('fieldTypes')]
    public function aFieldsTypeIsTheOneItsSchemaDocuments(FieldInterface $field, string $type): void
    {
        self::assertSame($type, ValueTypes::of($field));
        if (!$field instanceof Obj && !$field instanceof OneOf && !$field instanceof Map && !$field instanceof ArrayList) {
            self::assertSame($type, (new SchemaProjector())->projectField($field, true)->get('type'));
        }
    }

    #[Test]
    public function aRelationHasNoValueType(): void
    {
        self::assertNull(ValueTypes::of(BelongsTo::make('artist', 'artists')->build()));
    }

    /**
     * @return iterable<string, array{string, mixed, bool}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['string', 'x', true];
        yield 'int is not a string' => ['string', 42, false];
        yield 'bool is not a string' => ['string', true, false];
        yield 'list is not a string' => ['string', ['a'], false];
        yield 'bool' => ['boolean', false, true];
        yield '"yes" is not a bool' => ['boolean', 'yes', false];
        yield '"false" is not a bool' => ['boolean', 'false', false];
        yield '1 is not a bool' => ['boolean', 1, false];
        yield 'int' => ['integer', 3, true];
        yield 'whole float is an integer' => ['integer', 3.0, true];
        yield 'fraction is not an integer' => ['integer', 3.5, false];
        yield 'huge float is not an integer' => ['integer', 1e30, false];
        yield '"1" is not an integer' => ['integer', '1', false];
        yield 'int is a number' => ['number', 3, true];
        yield 'float is a number' => ['number', 3.5, true];
        yield '"1.5" is not a number' => ['number', '1.5', false];
        yield 'true is not a number' => ['number', true, false];
        yield 'map is an object' => ['object', ['a' => 1], true];
        yield 'empty is an object' => ['object', [], true];
        yield 'stdClass is an object' => ['object', new \stdClass(), true];
        yield 'list is not an object' => ['object', [1, 2], false];
        yield 'string is not an object' => ['object', 'x', false];
        yield 'list is an array' => ['array', [1, 2], true];
        yield 'empty is an array' => ['array', [], true];
        yield 'map is not an array' => ['array', ['a' => 1], false];
        yield 'string is not an array' => ['array', 'x', false];
    }

    #[Test]
    #[DataProvider('values')]
    public function aValueMatchesItsJsonType(string $type, mixed $value, bool $matches): void
    {
        self::assertSame($matches, ValueTypes::matches($type, $value));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function writes(): iterable
    {
        yield 'top-level attributes of every kind' => [
            [
                'name' => 42,
                'contact' => true,
                'kind' => 7,
                'count' => '1',
                'priority' => '2',
                'ratio' => '1.5',
                'flag' => 'yes',
                'day' => 20260101,
                'at' => false,
                'clock' => 1200,
                'tags' => 'a,b',
                'scores' => ['x' => 1],
                'hash' => [1, 2],
                'settings' => 'dark',
                'options' => 'all',
                'block' => 'tagged',
            ],
            [
                '/data/attributes/name' => 'string',
                '/data/attributes/contact' => 'string',
                '/data/attributes/kind' => 'string',
                '/data/attributes/count' => 'integer',
                '/data/attributes/priority' => 'integer',
                '/data/attributes/ratio' => 'number',
                '/data/attributes/flag' => 'boolean',
                '/data/attributes/day' => 'string',
                '/data/attributes/at' => 'string',
                '/data/attributes/clock' => 'string',
                '/data/attributes/tags' => 'array',
                '/data/attributes/scores' => 'array',
                '/data/attributes/settings' => 'object',
                '/data/attributes/options' => 'object',
                '/data/attributes/block' => 'object',
            ],
        ];
        yield 'well-typed values at every depth' => [
            [
                'name' => 'n',
                'count' => 3,
                'ratio' => 2,
                'flag' => false,
                'tags' => ['a'],
                'scores' => [1, 2.0],
                'hash' => ['any' => ['thing' => true]],
                'settings' => ['theme' => 'dark', 'limits' => ['rows' => 5]],
                'options' => ['notifyPublishers' => true, 'target' => ['name' => 'x', 'weight' => 1.5]],
                'rows' => [['Key' => 'a', 'Vendor' => 'b']],
                'mapping' => ['entries' => [['target' => ['name' => 'x']]]],
                'block' => ['kind' => 'tagged', 'label' => 'l', 'at' => '2026-01-01T00:00:00Z'],
                'blocks' => [['kind' => 'tagged', 'label' => 'l']],
            ],
            [],
        ];
        yield 'Obj and Map children' => [
            [
                'settings' => ['theme' => 3, 'limits' => ['rows' => '5']],
                'options' => ['notifyPublishers' => 'yes', 'target' => ['name' => 42, 'weight' => '1']],
            ],
            [
                '/data/attributes/settings/theme' => 'string',
                '/data/attributes/settings/limits/rows' => 'integer',
                '/data/attributes/options/notifyPublishers' => 'boolean',
                '/data/attributes/options/target/name' => 'string',
                '/data/attributes/options/target/weight' => 'number',
            ],
        ];
        yield 'open members and list elements' => [
            [
                'rows' => [['Key' => 42, 'Vendor' => true, 'a/b' => ['x']], 'row', null],
                'mapping' => ['entries' => [['target' => ['name' => 42]], ['target' => 'x']]],
                'tags' => ['a', 1, null],
                'scores' => [1, '2', 2.5],
            ],
            [
                '/data/attributes/tags/1' => 'string',
                '/data/attributes/tags/2' => 'string',
                '/data/attributes/scores/1' => 'integer',
                '/data/attributes/scores/2' => 'integer',
                '/data/attributes/rows/0/Key' => 'string',
                '/data/attributes/rows/0/Vendor' => 'string',
                '/data/attributes/rows/0/a~1b' => 'string',
                '/data/attributes/rows/1' => 'object',
                '/data/attributes/rows/2' => 'object',
                '/data/attributes/mapping/entries/0/target/name' => 'string',
                '/data/attributes/mapping/entries/1/target' => 'object',
            ],
        ];
        yield 'OneOf variants, as an attribute and as list elements' => [
            [
                'block' => ['kind' => 'tagged', 'label' => 5, 'at' => 0],
                'blocks' => [['kind' => 'tagged', 'label' => true], ['kind' => 'nope', 'label' => 1]],
            ],
            [
                '/data/attributes/block/label' => 'string',
                '/data/attributes/block/at' => 'string',
                '/data/attributes/blocks/0/label' => 'string',
            ],
        ];
        yield 'null where the field is not nullable' => [
            [
                'name' => null,
                'count' => null,
                'flag' => null,
                'settings' => ['theme' => null, 'limits' => ['rows' => null]],
                'options' => ['notifyPublishers' => null, 'target' => ['name' => null]],
                'rows' => [['Key' => null, 'Vendor' => null]],
                'block' => ['kind' => 'tagged', 'label' => null],
            ],
            [
                '/data/attributes/name' => 'string',
                '/data/attributes/count' => 'integer',
                '/data/attributes/flag' => 'boolean',
                '/data/attributes/settings/theme' => 'string',
                '/data/attributes/settings/limits/rows' => 'integer',
                '/data/attributes/options/notifyPublishers' => 'boolean',
                '/data/attributes/rows/0/Key' => 'string',
                '/data/attributes/rows/0/Vendor' => 'string',
                '/data/attributes/block/label' => 'string',
            ],
        ];
        yield 'null where the field is nullable' => [
            [
                'nickname' => null,
                'ratio' => null,
                'day' => null,
                'tags' => null,
                'hash' => null,
                'settings' => null,
                'options' => ['target' => ['name' => null], 'note' => null],
                'rows' => null,
                'mapping' => ['entries' => [['target' => ['name' => 'x', 'alias' => null]]]],
                'block' => null,
                'blocks' => null,
            ],
            [],
        ];
    }

    /**
     * @param array<string, mixed>  $attributes
     * @param array<string, string> $expected   type by pointer
     */
    #[Test]
    #[DataProvider('writes')]
    public function everyValueOfTheWrongTypeIsFoundAtItsPointer(array $attributes, array $expected): void
    {
        $found = ValueTypes::mismatchesIn(self::fields(), $attributes, true, new StubJsonApiRequest());

        self::assertSame($expected, \array_combine(
            \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, $found),
            \array_map(static fn(ValueTypeMismatch $m): string => $m->expected, $found),
        ));
    }

    #[Test]
    public function aShapeHydrationRefusesIsLeftToHydration(): void
    {
        $attributes = [
            'hash' => [1, 2],
            'settings' => ['limits' => ['a', 'b']],
            'options' => [true],
            'rows' => ['Key' => 'a'],
            'mapping' => ['entries' => [['target' => ['x']]]],
            'block' => ['tagged'],
            'tags' => ['a' => 'b'],
        ];

        $found = ValueTypes::mismatchesIn(self::fields(), $attributes, true, new StubJsonApiRequest());

        self::assertSame(['/data/attributes/tags'], \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, $found));
    }

    #[Test]
    public function aShapeUnderAWriteHookIsAMismatch(): void
    {
        $fields = [
            Obj::make('filled')->fields(Obj::make('inner')->fields(Str::make('a')))->fillUsing(static fn(mixed $model): mixed => $model)->build(),
            ArrayList::make('imported')->of(Obj::make('row')->fields(Str::make('a')))->fillUsing(static fn(mixed $model): mixed => $model)->build(),
        ];

        $found = ValueTypes::mismatchesIn($fields, ['filled' => ['inner' => ['x']], 'imported' => ['a' => 'b']], true, new StubJsonApiRequest());

        self::assertSame(['/data/attributes/filled/inner', '/data/attributes/imported'], \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, $found));
    }

    #[Test]
    public function aMismatchCarriesItsFieldAndValue(): void
    {
        [$mismatch] = ValueTypes::mismatchesIn(self::fields(), ['options' => ['notifyPublishers' => 1]], true, new StubJsonApiRequest());

        self::assertSame('notifyPublishers', $mismatch->field->name());
        self::assertSame(1, $mismatch->value);
        self::assertFalse($mismatch->isNull());
        self::assertTrue($mismatch->covers('/data/attributes/options/notifyPublishers'));
        self::assertFalse($mismatch->covers('/data/attributes/options/notifyPublishersX'));
    }

    #[Test]
    public function aHiddenOrReadOnlyFieldIsNotChecked(): void
    {
        $fields = [
            Str::make('secret')->hidden()->build(),
            Integer::make('views')->readOnly()->build(),
            Integer::make('stamp')->readOnlyOnUpdate()->build(),
            Obj::make('metrics')->additionalProperties(Integer::make('*')->readOnly())->build(),
        ];
        $attributes = ['secret' => 1, 'views' => 'x', 'stamp' => 'x', 'metrics' => ['a' => 'x']];

        self::assertSame([], ValueTypes::mismatchesIn($fields, $attributes, false, new StubJsonApiRequest()));
        self::assertCount(1, ValueTypes::mismatchesIn($fields, $attributes, true, new StubJsonApiRequest()));
    }

    #[Test]
    public function aWriteHookDoesNotExemptTheValue(): void
    {
        $fields = [
            Boolean::make('hooked')->deserializeUsing(static fn(mixed $value): bool => (bool) $value)->build(),
            Obj::make('filled')->fields(Integer::make('n'))->fillUsing(static fn(mixed $model): mixed => $model)->build(),
        ];

        $found = ValueTypes::mismatchesIn($fields, ['hooked' => 'yes', 'filled' => ['n' => '1']], true, new StubJsonApiRequest());

        self::assertSame(['/data/attributes/hooked', '/data/attributes/filled/n'], \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, $found));
    }

    #[Test]
    public function nullabilityFollowsTheWritesContextAndConditions(): void
    {
        $fields = [
            Str::make('onUpdate')->onUpdate(static function (StrBuilder $f): void {
                $f->nullable();
            })->build(),
            Str::make('forAdmins')->when(static fn(mixed $value, mixed $request): bool => $request !== null, static function (StrBuilder $f): void {
                $f->nullable();
            })->build(),
        ];
        $attributes = ['onUpdate' => null, 'forAdmins' => null];

        self::assertSame(['/data/attributes/onUpdate'], \array_map(static fn(ValueTypeMismatch $m): string => $m->pointer, ValueTypes::mismatchesIn($fields, $attributes, true, new StubJsonApiRequest())));
        self::assertSame([], ValueTypes::mismatchesIn($fields, $attributes, false, new StubJsonApiRequest()));
    }

    #[Test]
    public function aSingleValueIsCheckedAtTheGivenPointer(): void
    {
        $found = ValueTypes::mismatchesOf(Integer::make('position')->build(), '3', '/data/0/meta/pivot/position', true, new StubJsonApiRequest());

        self::assertSame('/data/0/meta/pivot/position', $found[0]->pointer);
        self::assertSame('integer', $found[0]->expected);
        self::assertSame([], ValueTypes::mismatchesOf(Integer::make('position')->build(), 3, '/data/0/meta/pivot/position', true, new StubJsonApiRequest()));
        self::assertCount(1, ValueTypes::mismatchesOf(Integer::make('position')->build(), null, '/data/0/meta/pivot/position', true));
        self::assertSame([], ValueTypes::mismatchesOf(Integer::make('position')->readOnlyOnCreate()->build(), '3', '/data/0/meta/pivot/position', true));
    }

    /**
     * @return list<FieldInterface>
     */
    private static function fields(): array
    {
        return [
            Id::make()->build(),
            Str::make('name')->build(),
            Str::make('nickname')->nullable()->build(),
            Email::make('contact')->build(),
            Str::make('kind')->enum(ValueTypesKind::class)->build(),
            Integer::make('count')->build(),
            Integer::make('priority')->enum(ValueTypesPriority::class)->build(),
            Decimal::make('ratio')->nullable()->build(),
            Boolean::make('flag')->build(),
            Date::make('day')->nullable()->build(),
            DateTime::make('at')->build(),
            Time::make('clock')->build(),
            ArrayList::make('tags')->nullable()->build(),
            ArrayList::make('scores')->of('integer')->build(),
            ArrayHash::make('hash')->nullable()->build(),
            Map::make('settings')->nullable()->fields(Str::make('theme'), Obj::make('limits')->additionalProperties(Integer::make('*')))->build(),
            Obj::make('options')->fields(
                Boolean::make('notifyPublishers'),
                Str::make('note')->nullable(),
                Obj::make('target')->fields(Str::make('name')->nullable(), Decimal::make('weight')),
            )->build(),
            ArrayList::make('rows')->nullable()->of(
                Obj::make('row')->fields(Str::make('Key')->required())->additionalProperties(Str::make('*')->maxLength(20)),
            )->build(),
            Obj::make('mapping')->fields(
                ArrayList::make('entries')->of(Obj::make('entry')->fields(Obj::make('target')->fields(Str::make('name'), Str::make('alias')->nullable()))),
            )->build(),
            OneOf::make('block')->nullable()->discriminator('kind')
                ->variant('tagged', Str::make('label'), DateTime::make('at'))
                ->build(),
            ArrayList::make('blocks')->nullable()->of(
                OneOf::make('block')->discriminator('kind')->variant('tagged', Str::make('label')),
            )->build(),
        ];
    }
}

enum ValueTypesKind: string
{
    case Album = 'album';
    case Single = 'single';
}

enum ValueTypesPriority: int
{
    case Low = 1;
    case High = 2;
}
