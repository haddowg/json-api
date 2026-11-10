<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\AbstractFieldBuilder;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An `extractUsing()` hook only says where a value comes from. The value then renders
 * through the declared shape as a stored one does, and the read schema is the one the
 * field projects with no hook. A `serializeUsing()` hook still owns the wire value.
 */
#[CoversClass(Obj::class)]
#[CoversClass(Map::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(ArrayList::class)]
#[CoversClass(Union::class)]
#[CoversClass(SchemaProjector::class)]
final class ExtractKeepsDeclaredShapeTest extends TestCase
{
    private const string AT = '2024-07-01T12:30:00+00:00';

    #[Test]
    public function aClosedObjDropsUndeclaredKeysAndFormatsItsChildren(): void
    {
        $field = Obj::make('values')
            ->fields(DateTime::make('seenAt'), Decimal::make('amount'), Date::make('due'))
            ->extractUsing(static fn(mixed $model): array => [
                'seenAt' => new \DateTimeImmutable(self::AT),
                'amount' => '12.50',
                'due' => new \DateTimeImmutable('2024-08-01'),
                'internal' => 'secret',
            ])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['seenAt' => self::AT, 'amount' => 12.5, 'due' => '2024-08-01'], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function anObjectValueRendersThroughTheChildren(): void
    {
        $value = new class {
            public string $name = 'n';
            public string $internal = 'x';

            public function getAmount(): string
            {
                return '3.0';
            }
        };
        $field = Obj::make('values')
            ->fields(Str::make('name'), Decimal::make('amount'))
            ->extractUsing(static fn(mixed $model): object => $value)
            ->build();

        self::assertSame(['name' => 'n', 'amount' => 3.0], $this->render($field));
    }

    #[Test]
    public function aMapRendersANestedObjThroughItsDeclaredShape(): void
    {
        $field = Map::make('profile')
            ->fields(
                Str::make('label'),
                Obj::make('geo')->fields(Decimal::make('lat'), Decimal::make('lng')),
            )
            ->extractUsing(static fn(mixed $model): array => [
                'label' => 'home',
                'geo' => ['lat' => '51.5', 'lng' => '-0.12', 'alt' => 30],
                'stray' => true,
            ])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['label' => 'home', 'geo' => ['lat' => 51.5, 'lng' => -0.12]], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aListRendersEachElementThroughItsDeclaredShape(): void
    {
        $field = ArrayList::make('sources')
            ->of(Obj::make('source')->fields(Str::make('href'), DateTime::make('retrievedAt')))
            ->extractUsing(static fn(mixed $model): array => [
                ['href' => 'a', 'retrievedAt' => new \DateTimeImmutable(self::AT), 'debug' => 1],
                ['href' => 'b', 'retrievedAt' => null],
            ])
            ->build();

        $rendered = $this->render($field);

        self::assertSame([
            ['href' => 'a', 'retrievedAt' => self::AT],
            ['href' => 'b', 'retrievedAt' => null],
        ], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aDiscriminatedOneOfSelectsItsVariantAndDropsOtherKeys(): void
    {
        $field = OneOf::make('shape')
            ->discriminator('kind')
            ->variant('circle', Decimal::make('radius'))
            ->variant('square', Decimal::make('side'))
            ->extractUsing(static fn(mixed $model): array => ['kind' => 'square', 'side' => '2', 'radius' => '9', 'colour' => 'red'])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['kind' => 'square', 'side' => 2.0], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function anUndiscriminatedOneOfSelectsItsVariantBySignature(): void
    {
        $field = OneOf::make('contact')
            ->variant('email', Str::make('address')->required())
            ->variant('phone', Str::make('number')->required(), Str::make('extension'))
            ->extractUsing(static fn(mixed $model): object => (object) ['number' => '555', 'note' => 'x'])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['number' => '555', 'extension' => null], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aListOfOneOfSelectsEachElementsVariant(): void
    {
        $field = ArrayList::make('steps')
            ->of(OneOf::make('step')->discriminator('op')->variant('trim', Str::make('mode'))->variant('pad', Decimal::make('width')))
            ->extractUsing(static fn(mixed $model): array => [
                ['op' => 'pad', 'width' => '4', 'mode' => 'left'],
                ['op' => 'trim', 'mode' => 'both'],
            ])
            ->build();

        $rendered = $this->render($field);

        self::assertSame([['op' => 'pad', 'width' => 4.0], ['op' => 'trim', 'mode' => 'both']], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aUnionSelectsTheMemberThatFormatsItsValue(): void
    {
        $date = Union::make('when')->of(Date::make(), Boolean::make())->extractUsing(static fn(mixed $model): \DateTimeImmutable => new \DateTimeImmutable('2024-08-01T10:00:00Z'))->build();
        $flag = Union::make('when')->of(Date::make(), Boolean::make())->extractUsing(static fn(mixed $model): bool => false)->build();

        self::assertSame('2024-08-01', $this->render($date));
        self::assertFalse($this->render($flag));
        $this->assertMatchesReadSchema($date, '2024-08-01');
    }

    #[Test]
    public function aListOfUnionsFormatsEachElementThroughItsMember(): void
    {
        $field = ArrayList::make('values')
            ->of(Union::of(Date::make(), Decimal::make()))
            ->extractUsing(static fn(mixed $model): array => [new \DateTimeImmutable('2024-08-01'), 1.5])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['2024-08-01', 1.5], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aSerializeHookStillOwnsTheWireValue(): void
    {
        $field = Obj::make('values')
            ->fields(Decimal::make('amount'))
            ->serializeUsing(static fn(mixed $model): array => ['amount' => '12.50', 'internal' => 'kept'])
            ->build();

        self::assertSame(['amount' => '12.50', 'internal' => 'kept'], $this->render($field));
        self::assertArrayNotHasKey('additionalProperties', $this->readSchema($field));
        self::assertArrayNotHasKey('required', $this->readSchema($field));
    }

    /**
     * @return iterable<string, array{AbstractFieldBuilder}>
     */
    public static function declaredShapes(): iterable
    {
        yield 'Obj' => [Obj::make('v')->fields(Str::make('a'), DateTime::make('b'))];
        yield 'open Obj' => [Obj::make('v')->fields(Str::make('a'))->additionalProperties(DateTime::make('*'))];
        yield 'Map' => [Map::make('v')->fields(Str::make('a'), Obj::make('nested')->fields(Decimal::make('n')))];
        yield 'discriminated OneOf' => [OneOf::make('v')->discriminator('kind')->variant('one', Str::make('a'))->variant('two', Decimal::make('b'))];
        yield 'undiscriminated OneOf' => [OneOf::make('v')->variant('one', Str::make('a')->required())->variant('two', Str::make('b')->required())];
        yield 'list of Obj' => [ArrayList::make('v')->of(Obj::make('e')->fields(Str::make('a')))];
        yield 'list of OneOf' => [ArrayList::make('v')->of(OneOf::make('e')->discriminator('kind')->variant('one', Str::make('a')))];
        yield 'list of Union' => [ArrayList::make('v')->of(Union::of(Date::make(), Decimal::make()))];
        yield 'Union' => [Union::make('v')->of(Date::make(), Decimal::make(), Boolean::make())];
    }

    #[Test]
    #[DataProvider('declaredShapes')]
    public function anExtractedValueProjectsTheShapeItWouldWithNoHook(AbstractFieldBuilder $builder): void
    {
        $plain = (clone $builder)->build();
        $extracted = (clone $builder)->extractUsing(static fn(mixed $model): mixed => null)->build();
        $computed = (clone $builder)->computedUsing(static fn(mixed $model): mixed => null)->build();

        self::assertSame($this->readSchema($plain), $this->readSchema($extracted));
        self::assertSame($this->readSchema($plain), $this->readSchema($computed));
    }

    private function render(FieldInterface $field): mixed
    {
        return $field->serialize([], new StubJsonApiRequest(), $field->name());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readSchema(FieldInterface $field): array
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], RepresentationContext::Read)->toArray();
        self::assertIsArray($attributes['properties'] ?? null);
        $schema = $attributes['properties'][$field->name()];
        self::assertIsArray($schema);

        return $schema;
    }

    private function assertMatchesReadSchema(FieldInterface $field, mixed $rendered): void
    {
        $schema = \json_encode($this->readSchema($field), \JSON_THROW_ON_ERROR);
        $result = (new Validator())->validate(\json_decode(\json_encode($rendered, \JSON_THROW_ON_ERROR)), $schema);

        self::assertTrue($result->isValid(), $result->error()?->message() ?? '');
    }
}
