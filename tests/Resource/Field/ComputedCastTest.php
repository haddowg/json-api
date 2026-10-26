<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A computed value goes through the field's own cast, as a stored column does, and a
 * value the closure already put in wire form comes out unchanged.
 */
#[CoversClass(AbstractFieldValue::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(Map::class)]
final class ComputedCastTest extends TestCase
{
    private const string AT = '2024-07-01T12:30:00+00:00';

    #[Test]
    public function aComputedDateTimeIsFormatted(): void
    {
        $field = DateTime::make('seenAt')->computedUsing(static fn(): \DateTimeImmutable => new \DateTimeImmutable(self::AT))->build();

        $rendered = $this->render($field);

        self::assertSame(self::AT, $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function anAlreadyFormattedComputedDateIsUnchanged(): void
    {
        $field = DateTime::make('seenAt')->format('d/m/Y')->computedUsing(static fn(): string => '2024-07-01T12:30:00Z')->build();

        self::assertSame('2024-07-01T12:30:00Z', $this->render($field));
    }

    /**
     * Each cast applied to its own output gives the same output, so a closure may return
     * either the domain value or its wire form.
     *
     * @param \Closure(mixed): FieldInterface $field
     */
    #[Test]
    #[DataProvider('castsOnComputedValues')]
    public function everyCastIsAppliedAndIdempotent(\Closure $field, mixed $domain, mixed $wire): void
    {
        $expected = \json_encode($wire, \JSON_THROW_ON_ERROR);

        self::assertSame($expected, \json_encode($this->render($field($domain)), \JSON_THROW_ON_ERROR));
        self::assertSame($expected, \json_encode($this->render($field($wire)), \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{\Closure(mixed): FieldInterface, mixed, mixed}>
     */
    public static function castsOnComputedValues(): array
    {
        $computed = static fn(string $class): \Closure => static fn(mixed $value): FieldInterface => $class::make('x')->computedUsing(static fn(): mixed => $value)->build();

        return [
            'date-time' => [$computed(DateTime::class), new \DateTimeImmutable(self::AT), self::AT],
            'date' => [$computed(Date::class), new \DateTimeImmutable(self::AT), '2024-07-01'],
            'time' => [$computed(Time::class), new \DateTimeImmutable(self::AT), '12:30:00'],
            'integer' => [$computed(Integer::class), '42', 42],
            'decimal' => [$computed(Decimal::class), '12.5', 12.5],
            'boolean' => [$computed(Boolean::class), 1, true],
            'string' => [$computed(Str::class), 7, '7'],
            'list' => [$computed(ArrayList::class), ['a' => 'x', 'b' => 'y'], ['x', 'y']],
            'hash' => [$computed(ArrayHash::class), ['b' => 1], ['b' => 1]],
            'empty hash' => [$computed(ArrayHash::class), [], new \stdClass()],
        ];
    }

    #[Test]
    public function aComputedIdIsNotEncodedAgain(): void
    {
        $encoder = new class implements IdEncoderInterface {
            public function encode(mixed $storageKey): string
            {
                return 'enc-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
            }

            public function decode(string $wireId): mixed
            {
                return \substr($wireId, 4);
            }
        };
        $field = Id::make()->encodeUsing($encoder)->extractUsing(static fn(): string => 'enc-7')->build();

        self::assertSame('enc-7', $this->render($field));
    }

    #[Test]
    public function aComputedOneOfRunsTheMatchedVariantsChildren(): void
    {
        $field = OneOf::make('event')
            ->discriminator('kind')
            ->variant('visit', DateTime::make('at'), Integer::make('pages'))
            ->variant('note', Str::make('text'))
            ->computedUsing(static fn(): array => ['kind' => 'visit', 'at' => new \DateTimeImmutable(self::AT), 'pages' => '3'])
            ->build();

        $rendered = $this->render($field);

        self::assertEquals(['kind' => 'visit', 'at' => self::AT, 'pages' => 3], (array) $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aComputedOneOfObjectValueRunsItsChildren(): void
    {
        $value = new \stdClass();
        $value->kind = 'visit';
        $value->at = new \DateTimeImmutable(self::AT);

        $field = OneOf::make('event')
            ->discriminator('kind')
            ->variant('visit', DateTime::make('at'))
            ->computedUsing(static fn(): object => $value)
            ->build();

        self::assertEquals(['kind' => 'visit', 'at' => self::AT], (array) $this->render($field));
    }

    #[Test]
    public function aComputedOneOfNamingNoVariantIsEmittedAsItIs(): void
    {
        $field = OneOf::make('event')
            ->discriminator('kind')
            ->variant('visit', Integer::make('pages'))
            ->computedUsing(static fn(): array => ['kind' => 'other', 'pages' => '3'])
            ->build();

        self::assertEquals(['kind' => 'other', 'pages' => '3'], (array) $this->render($field));
        self::assertNull($this->render(OneOf::make('event')->discriminator('kind')->variant('visit')->computedUsing(static fn(): mixed => null)->build()));
    }

    #[Test]
    public function aComputedMapSpreadsItsChildrenOverTheComputedValue(): void
    {
        $field = Map::make('stats')
            ->fields(DateTime::make('lastSeen'), Integer::make('visits'))
            ->computedUsing(static fn(): array => ['lastSeen' => new \DateTimeImmutable(self::AT), 'visits' => '3'])
            ->build();

        $rendered = $this->render($field);

        self::assertEquals(['lastSeen' => self::AT, 'visits' => 3], (array) $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    private function render(FieldInterface $field): mixed
    {
        return $field->serialize([], new StubJsonApiRequest(), $field->name());
    }

    private function assertMatchesReadSchema(FieldInterface $field, mixed $rendered): void
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], RepresentationContext::Read)->toArray();
        self::assertIsArray($attributes['properties'] ?? null);
        $schema = \json_encode($attributes['properties'][$field->name()], \JSON_THROW_ON_ERROR);

        $result = (new Validator())->validate(\json_decode(\json_encode($rendered, \JSON_THROW_ON_ERROR)), $schema);

        self::assertTrue($result->isValid(), $result->error()?->message() ?? '');
    }
}
