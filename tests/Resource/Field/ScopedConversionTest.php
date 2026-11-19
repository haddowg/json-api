<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Field\AbstractFieldBuilder;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldState;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An `enum()`, an `in()` over an enum's cases or a `deserializeUsing()` declared inside
 * `onCreate()` or `onUpdate()` converts only that write's value: the other write keeps
 * the value as sent, unless the field declares its own conversion for it. One declared
 * inside `when()` converts on every write.
 */
#[CoversClass(AbstractFieldValue::class)]
#[CoversClass(AbstractFieldBuilder::class)]
#[CoversClass(ArrayList::class)]
#[CoversClass(FieldState::class)]
final class ScopedConversionTest extends TestCase
{
    #[Test]
    public function anEnumDeclaredOnCreateConvertsOnlyACreate(): void
    {
        $field = Str::make('state')->onCreate(static fn(StrBuilder $field) => $field->enum(ScopedConversionState::class))->build();

        self::assertSame(['state' => ScopedConversionState::Running], $this->hydrate($field, 'running', true));
        self::assertSame(['state' => 'running'], $this->hydrate($field, 'running', false));
    }

    #[Test]
    public function anEnumDeclaredOnUpdateConvertsOnlyAnUpdate(): void
    {
        $field = Str::make('state')->onUpdate(static fn(StrBuilder $field) => $field->in([ScopedConversionState::Running, ScopedConversionState::Cancelled]))->build();

        self::assertSame(['state' => 'running'], $this->hydrate($field, 'running', true));
        self::assertSame(['state' => ScopedConversionState::Running], $this->hydrate($field, 'running', false));
    }

    #[Test]
    public function aFillHookReceivesTheCaseOnlyOnTheScopedWrite(): void
    {
        $received = [];
        $field = Str::make('state')
            ->onCreate(static fn(StrBuilder $field) => $field->enum(ScopedConversionState::class))
            ->fillUsing(static function (mixed $model, mixed $value) use (&$received): mixed {
                $received[] = $value;

                return $model;
            })
            ->build();

        $this->hydrate($field, 'running', true);
        $this->hydrate($field, 'running', false);

        self::assertSame([ScopedConversionState::Running, 'running'], $received);
    }

    #[Test]
    public function anEnumDeclaredOnTheFieldConvertsTheOtherWrite(): void
    {
        $field = Str::make('state')
            ->enum(ScopedConversionState::class)
            ->onCreate(static fn(StrBuilder $field) => $field->maxLength(10))
            ->build();

        self::assertSame(['state' => ScopedConversionState::Running], $this->hydrate($field, 'running', true));
        self::assertSame(['state' => ScopedConversionState::Running], $this->hydrate($field, 'running', false));
    }

    #[Test]
    public function anEnumDeclaredInsideWhenConvertsEveryWrite(): void
    {
        $field = Str::make('state')->when(static fn(mixed $value): bool => true, static fn(StrBuilder $field) => $field->enum(ScopedConversionState::class))->build();

        // when() wraps the In, so no write converts, as before.
        self::assertSame(['state' => 'running'], $this->hydrate($field, 'running', true));
        self::assertSame(['state' => 'running'], $this->hydrate($field, 'running', false));
    }

    #[Test]
    public function aDeserializeHookDeclaredOnCreateRunsOnlyOnACreate(): void
    {
        $field = Str::make('name')
            ->onCreate(static fn(StrBuilder $field) => $field->deserializeUsing(static fn(mixed $value): string => \strtoupper((string) $value)))
            ->build();

        self::assertSame(['name' => 'ADA'], $this->hydrate($field, 'ada', true));
        self::assertSame(['name' => 'ada'], $this->hydrate($field, 'ada', false));
    }

    #[Test]
    public function eachWriteRunsItsOwnDeserializeHookOverTheFieldsOne(): void
    {
        $field = Str::make('name')
            ->deserializeUsing(static fn(string $value): string => 'field:' . $value)
            ->onUpdate(static fn(StrBuilder $field) => $field->deserializeUsing(static fn(mixed $value): string => 'update:' . (\is_string($value) ? $value : '')))
            ->build();

        self::assertSame(['name' => 'field:ada'], $this->hydrate($field, 'ada', true));
        self::assertSame(['name' => 'update:ada'], $this->hydrate($field, 'ada', false));
    }

    #[Test]
    public function aListsEnumDeclaredOnCreateConvertsOnlyACreate(): void
    {
        $field = ArrayList::make('states')->onCreate(static fn($field) => $field->each(new In(['running', 'cancelled'], enumClass: ScopedConversionState::class)))->build();

        self::assertSame(['states' => [ScopedConversionState::Running]], $this->hydrate($field, ['running'], true));
        self::assertSame(['states' => ['running']], $this->hydrate($field, ['running'], false));
    }

    #[Test]
    public function aCastForAWriteConvertsOnlyWhatThatWriteDeclares(): void
    {
        $field = Str::make('state')->onCreate(static fn(StrBuilder $field) => $field->enum(ScopedConversionState::class))->build();

        self::assertSame(ScopedConversionState::Running, $field->castWireValueFor('running', true));
        self::assertSame('running', $field->castWireValueFor('running', false));
        self::assertSame('running', $field->castWireValue('running'));
    }

    #[Test]
    public function aScopedCaseStillRendersAsItsBackingValue(): void
    {
        $field = Str::make('state')->onCreate(static fn(StrBuilder $field) => $field->enum(ScopedConversionState::class))->build();

        self::assertSame('running', $field->serialize(['state' => ScopedConversionState::Running], new StubJsonApiRequest(), 'state'));
    }

    private function hydrate(\haddowg\JsonApi\Resource\Field\FieldInterface $field, mixed $value, bool $creating): mixed
    {
        \assert($field instanceof AbstractFieldValue);

        return $field->hydrate([], $value, [], new StubJsonApiRequest(), $creating);
    }
}

enum ScopedConversionState: string
{
    case Running = 'running';
    case Cancelled = 'cancelled';
}
