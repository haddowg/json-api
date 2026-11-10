<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Field\UnionBuilder;
use haddowg\JsonApi\Resource\UnionValue;
use haddowg\JsonApi\Resource\UnionValues;
use haddowg\JsonApi\Resource\ValueTypes;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Core finds each place a union's member applies in a write body, with its pointer and the
 * member the value selects, so adapters translate only that member's constraints there.
 */
#[CoversClass(UnionValues::class)]
#[CoversClass(UnionValue::class)]
#[CoversClass(ValueTypes::class)]
final class UnionValuesTest extends TestCase
{
    #[Test]
    public function aUnionAttributeYieldsItsSelectedMemberAtItsPointer(): void
    {
        $field = $this->value()->build();

        foreach ([
            'a string' => ['abc', 'string'],
            'a number' => [2, 'number'],
            'a boolean' => [false, 'boolean'],
        ] as $case => [$value, $type]) {
            $found = $this->found([$field], ['value' => $value]);

            self::assertCount(1, $found, $case);
            self::assertSame('/data/attributes/value', $found[0]->pointer, $case);
            self::assertSame($field, $found[0]->union, $case);
            self::assertSame($type, ValueTypes::of($found[0]->member), $case);
            self::assertSame($value, $found[0]->value, $case);
        }
    }

    #[Test]
    public function aListMemberYieldsItselfThenEachElementThroughTheElementUnion(): void
    {
        $field = $this->value()->build();

        $found = $this->found([$field], ['value' => ['a', 1, true]]);

        self::assertSame(
            ['/data/attributes/value' => 'array', '/data/attributes/value/0' => 'string', '/data/attributes/value/1' => 'number', '/data/attributes/value/2' => 'boolean'],
            self::byPointer($found),
        );
        self::assertSame('', $found[1]->union->name());
    }

    #[Test]
    public function nullAndAValueNoMemberTakesAreLeftToPresenceAndTypes(): void
    {
        $field = $this->value()->build();

        self::assertSame([], $this->found([$field], ['value' => null]));
        self::assertSame([], $this->found([$field], ['value' => ['k' => 'v']]));
        self::assertSame(['/data/attributes/value' => 'array'], self::byPointer($this->found([$field], ['value' => [['nested']]])));
    }

    #[Test]
    public function unionsAreFoundAtAnyDepth(): void
    {
        $union = static fn(string $name): UnionBuilder => Union::make($name)->of(Str::make(), Decimal::make());
        $fields = [
            Obj::make('settings')->fields($union('cap'))->additionalProperties($union('*'))->build(),
            Map::make('limits')->fields($union('floor'))->build(),
            OneOf::make('rule')->discriminator('kind')->variant('cap', $union('amount'))->build(),
            ArrayList::make('rows')->of(Obj::make('row')->fields($union('cell')))->build(),
            ArrayList::make('values')->of(Union::of(Str::make(), Boolean::make()))->build(),
        ];

        $found = $this->found($fields, [
            'settings' => ['cap' => 1, 'a/b' => 'x'],
            'limits' => ['floor' => 'low'],
            'rule' => ['kind' => 'cap', 'amount' => 3.5],
            'rows' => [['cell' => 'c'], ['cell' => 2]],
            'values' => [true, 'v'],
        ]);

        self::assertSame([
            '/data/attributes/settings/cap' => 'number',
            '/data/attributes/settings/a~1b' => 'string',
            '/data/attributes/limits/floor' => 'string',
            '/data/attributes/rule/amount' => 'number',
            '/data/attributes/rows/0/cell' => 'string',
            '/data/attributes/rows/1/cell' => 'number',
            '/data/attributes/values/0' => 'boolean',
            '/data/attributes/values/1' => 'string',
        ], self::byPointer($found));
    }

    #[Test]
    public function aHiddenOrReadOnlyUnionIsNotWalked(): void
    {
        $fields = [
            Union::make('a')->of(Str::make())->hidden()->build(),
            Union::make('b')->of(Str::make())->readOnly()->build(),
            Union::make('c')->of(Str::make())->readOnly(static fn(JsonApiRequestInterface $request): bool => true)->build(),
        ];

        self::assertSame([], $this->found($fields, ['a' => 'x', 'b' => 'x', 'c' => 'x']));
    }

    #[Test]
    public function aRequiredUnionRefusesOnlyAnEmptyStringOrList(): void
    {
        $field = $this->value()->required()->build();

        foreach (['' => true, 'x' => false] as $value => $refused) {
            $found = $this->found([$field], ['value' => (string) $value]);
            self::assertTrue($found[0]->nonEmpty);
            self::assertSame($refused, $found[0]->isRefusedAsEmpty());
        }
        $empty = $this->found([$field], ['value' => []]);
        self::assertTrue($empty[0]->isRefusedAsEmpty());

        foreach ([false, 0, 0.0] as $value) {
            $found = $this->found([$field], ['value' => $value]);
            self::assertFalse($found[0]->nonEmpty, \var_export($value, true));
            self::assertFalse($found[0]->isRefusedAsEmpty(), \var_export($value, true));
        }
    }

    #[Test]
    public function requiredFollowsTheWritesContext(): void
    {
        $field = Union::make('value')->of(Str::make(), Integer::make())->requiredOnCreate()->build();
        $conditional = Union::make('value')->of(Str::make())
            ->when(static fn(mixed $value): bool => $value === '', static function (UnionBuilder $f): void {
                $f->required();
            })->build();

        self::assertTrue($this->found([$field], ['value' => ''], creating: true)[0]->nonEmpty);
        self::assertFalse($this->found([$field], ['value' => ''], creating: false)[0]->nonEmpty);
        self::assertTrue($this->found([$conditional], ['value' => ''])[0]->nonEmpty);
        self::assertFalse($this->found([$conditional], ['value' => 'x'])[0]->nonEmpty);
        // Elements of a list never hold a required union.
        self::assertFalse($this->found([$this->value()->required()->build()], ['value' => ['']])[1]->nonEmpty);
    }

    #[Test]
    public function aSingleValueIsWalkedFromItsOwnPointer(): void
    {
        $found = UnionValues::of($this->value()->build(), ['a'], '/data/relationships/tags/data/0/meta/pivot/value', true);

        self::assertSame(
            ['/data/relationships/tags/data/0/meta/pivot/value' => 'array', '/data/relationships/tags/data/0/meta/pivot/value/0' => 'string'],
            self::byPointer($found),
        );
    }

    #[Test]
    public function requiredRefusesEmptyForStringsListsAndObjectsOnly(): void
    {
        foreach ([
            'Str' => [Str::make('s')->build(), true],
            'ArrayList' => [ArrayList::make('l')->build(), true],
            'Obj' => [Obj::make('o')->build(), true],
            'Map' => [Map::make('m')->build(), true],
            'ArrayHash' => [ArrayHash::make('h')->build(), true],
            'Integer' => [Integer::make('i')->build(), false],
            'Decimal' => [Decimal::make('d')->build(), false],
            'Boolean' => [Boolean::make('b')->build(), false],
            'OneOf' => [OneOf::make('o')->discriminator('k')->variant('a')->build(), false],
            'Union' => [$this->value()->build(), false],
        ] as $case => [$field, $refuses]) {
            self::assertSame($refuses, ValueTypes::requiredRefusesEmpty($field), $case);
        }
    }

    /**
     * @param list<\haddowg\JsonApi\Resource\Field\FieldInterface> $fields
     * @param array<string, mixed>                                 $attributes
     *
     * @return list<UnionValue>
     */
    private function found(array $fields, array $attributes, bool $creating = true): array
    {
        return UnionValues::in($fields, $attributes, $creating, new StubJsonApiRequest());
    }

    /**
     * @param list<UnionValue> $found
     *
     * @return array<string, string|null>
     */
    private static function byPointer(array $found): array
    {
        $types = [];
        foreach ($found as $place) {
            $types[$place->pointer] = ValueTypes::of($place->member);
        }

        return $types;
    }

    private function value(): UnionBuilder
    {
        return Union::make('value')->of(
            Str::make()->maxLength(20),
            Decimal::make(),
            Boolean::make(),
            ArrayList::make()->maxItems(5)->of(Union::of(Str::make(), Decimal::make(), Boolean::make())),
        );
    }
}
