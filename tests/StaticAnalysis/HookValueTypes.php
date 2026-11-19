<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Ip;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Field\Uuid;

enum HookValueStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would. Each typed value parameter must pass, and each ignored error
 * in refused() marks a value type the field does not guarantee: if that call stopped
 * failing, the unmatched ignore would fail the run instead.
 */
final class HookValueTypes
{
    /**
     * @return list<FieldBuilderInterface>
     */
    public static function typed(): array
    {
        return [
            Str::make('title')->fillUsing(static fn(\ArrayObject $model, string $value): int => $model->count()),
            Str::make('subtitle')->deserializeUsing(static fn(string $value, array $data): string => \trim($value)),
            Email::make('email')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Url::make('url')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Uuid::make('uuid')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Slug::make('slug')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Ip::make('ip')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            DateTime::make('publishedAt')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Date::make('releasedOn')->deserializeUsing(static fn(string $value): string => $value),
            Time::make('opensAt')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Integer::make('plays')->fillUsing(static fn(\ArrayObject $model, int $value): int => $value),
            Decimal::make('rating')->fillUsing(static fn(\ArrayObject $model, int|float $value): float => (float) $value),
            Boolean::make('explicit')->fillUsing(static fn(\ArrayObject $model, bool $value): bool => $value),
            ArrayList::make('tags')->fillUsing(
                /** @param list<string> $value */
                static fn(\ArrayObject $model, array $value): string => \implode(',', $value),
            ),
            ArrayList::make('counts')->of('integer')->fillUsing(
                /** @param list<int> $value */
                static fn(\ArrayObject $model, array $value): int => \array_sum($value),
            ),
            ArrayList::make('states')->each(new In(['draft', 'live'], enumClass: HookValueStatus::class))->fillUsing(
                /** @param list<string|HookValueStatus> $value */
                static fn(\ArrayObject $model, array $value): int => \count($value),
            ),
            ArrayList::make('weights')->of('number')->deserializeUsing(
                /** @param list<int|float> $value */
                static fn(array $value): int|float => \array_sum($value),
            ),
            ArrayList::make('flags')->of('boolean')->fillUsing(
                /** @param list<bool> $value */
                static fn(\ArrayObject $model, array $value): bool => \in_array(true, $value, true),
            ),
            ArrayList::make('credits')->of(Obj::make('credit')->fields(Str::make('name')))->fillUsing(
                /** @param list<array<array-key, mixed>> $value */
                static fn(\ArrayObject $model, array $value): int => \count($value),
            ),
            ArrayList::make('values')->of(Union::of(Str::make(), Decimal::make()))->fillUsing(
                /** @param list<string|int|float|bool|list<mixed>> $value */
                static fn(\ArrayObject $model, array $value): int => \count($value),
            ),
            ArrayHash::make('extra')->fillUsing(
                /** @param array<array-key, mixed> $value */
                static fn(\ArrayObject $model, array $value): int => \count($value),
            ),
            Map::make('address')->fields(Str::make('city'))->fillUsing(
                /** @param array<string, mixed> $value */
                static fn(\ArrayObject $model, array $value): mixed => $value['city'] ?? null,
            ),
            Obj::make('settings')->fields(Str::make('theme'))->fillUsing(
                /** @param array<string, mixed> $value */
                static fn(\ArrayObject $model, array $value): mixed => $value['theme'] ?? null,
            ),
            Obj::make('labels')->additionalProperties(Str::make('*'))->fillUsing(
                /** @param array<array-key, mixed> $value */
                static fn(\ArrayObject $model, array $value): int => \count($value),
            ),
            OneOf::make('payment')->discriminator('kind')->variant('card', Str::make('number'))->fillUsing(
                /** @param array<string, mixed> $value */
                static fn(\ArrayObject $model, array $value): mixed => $value['kind'] ?? null,
            ),
            Union::make('size')->of(Str::make(), Integer::make())->fillUsing(
                /** @param string|int|float|bool|list<mixed> $value */
                static fn(\ArrayObject $model, mixed $value): mixed => $value,
            ),
            Union::make('dimension')->of(Str::make(), Integer::make())->fillUsing(
                static fn(\ArrayObject $model, string|int|float|bool|array $value): mixed => $value,
            ),
            Str::make('state')->enum(HookValueStatus::class)->fillUsing(
                static fn(\ArrayObject $model, HookValueStatus|string $value): mixed => $value,
            ),
            Str::make('phase')->in([HookValueStatus::Draft, HookValueStatus::Live])->fillUsing(
                static fn(\ArrayObject $model, HookValueStatus|string $value): mixed => $value,
            ),
            Str::make('mood')->in(['calm', 'loud'])->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Str::make('typedModelAndRest')->fillUsing(
                static fn(\ArrayObject $model, string $value, array $data, string $name): mixed => $data[$name] ?? $value,
            ),
            Str::make('constrained')->constrain(new MaxLength(5))->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Str::make('chained')->required()->maxLength(10)->sortable()->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
        ];
    }

    /**
     * @return list<FieldBuilderInterface>
     */
    public static function nullable(): array
    {
        return [
            Str::make('note')->nullable()->fillUsing(static fn(\ArrayObject $model, ?string $value): mixed => $value),
            Str::make('remark')->nullable()->deserializeUsing(static fn(?string $value): ?string => $value),
            Integer::make('rank')->nullable()->fillUsing(static fn(\ArrayObject $model, ?int $value): mixed => $value),
            Boolean::make('featured')->nullable()->fillUsing(static fn(\ArrayObject $model, ?bool $value): mixed => $value),
            Email::make('backupEmail')->nullable()->fillUsing(static fn(\ArrayObject $model, ?string $value): mixed => $value),
            DateTime::make('archivedAt')->nullable()->fillUsing(static fn(\ArrayObject $model, ?string $value): mixed => $value),
            ArrayList::make('aliases')->nullable()->of('integer')->fillUsing(
                /** @param list<int>|null $value */
                static fn(\ArrayObject $model, ?array $value): mixed => $value,
            ),
            Obj::make('meta')->nullable()->fields(Str::make('a'))->fillUsing(
                /** @param array<string, mixed>|null $value */
                static fn(\ArrayObject $model, ?array $value): mixed => $value,
            ),
            Str::make('state2')->enum(HookValueStatus::class)->nullable()->fillUsing(
                static fn(\ArrayObject $model, HookValueStatus|string|null $value): mixed => $value,
            ),
            Str::make('createOnlyNull')->onCreate(static function (StrBuilder $field): void {
                $field->nullable();
            })->fillUsing(static fn(\ArrayObject $model, ?string $value): mixed => $value),
            Str::make('updateOnlyNull')->onUpdate(static function (StrBuilder $field): void {
                $field->nullable();
            })->fillUsing(static fn(\ArrayObject $model, ?string $value): mixed => $value),
            Str::make('conditionalNull')->when(
                static fn(mixed $value, ?JsonApiRequestInterface $request): bool => $request === null,
                static function (StrBuilder $field): void {
                    $field->nullable();
                },
            )->fillUsing(static fn(\ArrayObject $model, ?string $value): mixed => $value),
            Str::make('constrainedNull')->constrain(new Nullable(Context::onlyCreate()))->fillUsing(
                static fn(\ArrayObject $model, ?string $value): mixed => $value,
            ),
            Str::make('nullableThenScoped')->nullable()->onCreate(static function (StrBuilder $field): void {
                $field->maxLength(3);
            })->fillUsing(static fn(\ArrayObject $model, ?string $value): mixed => $value),
        ];
    }

    /**
     * @return list<FieldBuilderInterface>
     */
    public static function untyped(): array
    {
        return [
            Str::make('mixedValue')->fillUsing(static fn(\ArrayObject $model, mixed $value): mixed => $value),
            Str::make('mixedNullable')->nullable()->fillUsing(static fn(mixed $model, mixed $value, array $data, string $name): mixed => $value),
            Integer::make('mixedDeserialized')->deserializeUsing(static fn(mixed $value, array $data): mixed => $value),
            Str::make('untypedValue')->fillUsing(static fn($model, $value, $data, $name) => $value),
            Str::make('deserializedThenFilled')
                ->deserializeUsing(static fn(string $value): string => \strtoupper($value))
                ->fillUsing(static fn(\ArrayObject $model, mixed $value): mixed => $value),
            Str::make('mixedCondition')->when(static fn(mixed $value): bool => $value !== null, static function (StrBuilder $field): void {
                $field->maxLength(3);
            }),
            Str::make('builderParam')->onCreate(static function ($field): void {
                $field->required();
            }),
        ];
    }

    /**
     * @return list<FieldBuilderInterface>
     */
    public static function refused(): array
    {
        return [
            // @phpstan-ignore argument.type (a string field's value is not an int)
            Str::make('wrongInt')->fillUsing(static fn(\ArrayObject $model, int $value): mixed => $value),
            // @phpstan-ignore argument.type (a string field's value is not an int)
            Str::make('wrongDeserialize')->deserializeUsing(static fn(int $value): int => $value),
            // @phpstan-ignore argument.type (an integer field's value is not a string)
            Integer::make('wrongString')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            // @phpstan-ignore argument.type (a number may be a float)
            Decimal::make('wrongNumber')->fillUsing(static fn(\ArrayObject $model, int $value): mixed => $value),
            // @phpstan-ignore argument.type (an enum list's elements are cases)
            ArrayList::make('wrongEnumList')->each(new In(['draft'], enumClass: HookValueStatus::class))->fillUsing(static fn(\ArrayObject $model, int $value): mixed => $value),
            // @phpstan-ignore argument.type (a boolean is not a string)
            Boolean::make('wrongBool')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            // @phpstan-ignore argument.type (a nullable field's value may be null)
            Str::make('wrongNullable')->nullable()->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            Str::make('wrongScoped')->onCreate(static function (StrBuilder $field): void {
                $field->nullable();
                // @phpstan-ignore argument.type (a field nullable on create may receive null)
            })->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            // @phpstan-ignore argument.type (an enum field's fillUsing receives the case)
            Str::make('wrongEnum')->enum(HookValueStatus::class)->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            // @phpstan-ignore argument.type (after deserializeUsing the value is mixed)
            Str::make('wrongAfterDeserialize')->deserializeUsing(static fn(string $value): string => $value)->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            // @phpstan-ignore argument.type (a list is not a string)
            ArrayList::make('wrongList')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            // @phpstan-ignore argument.type (an integer list is a list, not a number)
            ArrayList::make('wrongElements')->of('integer')->fillUsing(static fn(\ArrayObject $model, int $value): mixed => $value),
            // @phpstan-ignore argument.type (an object is an array, not a string)
            Obj::make('wrongObj')->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
            // @phpstan-ignore argument.type (a union's value may be any of its members' types)
            Union::make('wrongUnion')->of(Str::make(), Integer::make())->fillUsing(static fn(\ArrayObject $model, string $value): mixed => $value),
        ];
    }
}
