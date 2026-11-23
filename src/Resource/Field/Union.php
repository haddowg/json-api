<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Exception\AttributeValueInvalid;
use haddowg\JsonApi\OpenApi\EnumComponentCollector;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\ValueTypes;

/**
 * An attribute whose value is one of several JSON primitive types, or a list of them:
 * the built, readonly value object the engine walks. Authors declare one with
 * {@see make()}, which returns a mutable {@see UnionBuilder}, and an unnamed one for a
 * list's elements with {@see of()}.
 *
 * Each member is an unnamed field taking one JSON type: a string-family field ({@see Str}
 * and its facades, {@see DateTime}, {@see Date} or {@see Time}), a number ({@see Integer}
 * or {@see Decimal}), a {@see Boolean}, or an {@see ArrayList} of scalars or of a nested
 * union. A union holds at most one member per JSON type, so the type of a value always
 * names the one member whose rules apply and whose PHP type comes back. The one exception is
 * a number: a union may hold both an {@see Integer} and a {@see Decimal}, and then the form
 * the number arrived in picks between them, an `int` the `Integer` and a `float` the
 * `Decimal`, so `500` and `500.0` each keep their form. There is no discriminator.
 *
 * A written value's JSON type picks its member, which casts it and whose constraints
 * apply at the union's own pointer. A stored value's PHP type picks the member that
 * renders it. A stored value no member takes renders unchanged.
 */
final readonly class Union extends AbstractFieldValue implements NamedSchemaInterface, ProvidesFieldSchema, AcceptsDefaultOutputTimezoneInterface
{
    /**
     * The keywords that constrain only values of one JSON type, so a single schema can
     * carry them for every member at once.
     */
    private const TYPE_SCOPED = [
        ValueTypes::STRING => ['type', 'format', 'minLength', 'maxLength', 'pattern'],
        ValueTypes::INTEGER => ['type', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'],
        ValueTypes::NUMBER => ['type', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'],
        ValueTypes::BOOLEAN => ['type'],
        ValueTypes::ARRAY => ['type', 'minItems', 'maxItems', 'uniqueItems', 'items'],
    ];

    /**
     * The members, keyed by the JSON type each takes, `integer` for an {@see Integer} and
     * `number` for a {@see Decimal}, in the order string, integer, number, boolean, array.
     *
     * @var array<string, AbstractFieldValue>
     */
    private array $members;

    /**
     * @param list<FieldInterface> $members
     *
     * @throws \LogicException when a member is not a primitive field, two members take the same JSON type, or a member carries a rule that belongs to the union
     */
    public function __construct(FieldState $state, array $members = [])
    {
        parent::__construct($state);
        $this->members = self::keyed($state->name, $members);
    }

    /**
     * @return UnionBuilder<string|int|float|bool|list<mixed>>
     */
    public static function make(string $name): UnionBuilder
    {
        return new UnionBuilder($name);
    }

    /**
     * An unnamed union of `$members`, for a list's elements:
     * `ArrayList::make('values')->of(Union::of(Str::make(), Decimal::make()))`.
     *
     * @return UnionBuilder<string|int|float|bool|list<mixed>>
     */
    public static function of(FieldInterface|FieldBuilderInterface ...$members): UnionBuilder
    {
        return (new UnionBuilder(''))->of(...$members);
    }

    /**
     * The members, in the order string, integer, number, boolean, array.
     *
     * @return list<AbstractFieldValue>
     */
    public function members(): array
    {
        return \array_values($this->members);
    }

    /**
     * The member a written value's JSON type selects, or `null` when no member takes that
     * type, which a write refuses. `null` selects no member. With both an {@see Integer}
     * and a {@see Decimal} member, an `int` selects the `Integer` and a `float` the
     * `Decimal`, whole or not. With one of them, it takes every number it accepts.
     */
    public function memberFor(mixed $value): ?AbstractFieldValue
    {
        if ($value === null) {
            return null;
        }

        $numeric = $this->numericMemberFor($value);
        if ($numeric !== null) {
            return $numeric;
        }

        foreach ($this->members as $member) {
            if (ValueTypes::matches(self::valueTypeOf($member), $value)) {
                return $member;
            }
        }

        return null;
    }

    /**
     * The member that renders a stored value, chosen by its PHP type: a string, or a
     * `\DateTimeInterface` for a date member, to the string member, an int or float to the
     * number member, a bool to the boolean member and a list to the array member. With both
     * an {@see Integer} and a {@see Decimal} member, an int goes to the `Integer` and a
     * float to the `Decimal`. A backed enum goes by its value. `null` when no member takes
     * the value.
     */
    public function memberForStored(mixed $value): ?AbstractFieldValue
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (\is_int($value) || \is_float($value)) {
            return $this->numericMemberFor($value) ?? $this->members[ValueTypes::INTEGER] ?? $this->members[ValueTypes::NUMBER] ?? null;
        }

        $slot = match (true) {
            \is_string($value) => ValueTypes::STRING,
            $value instanceof \DateTimeInterface => ($this->members[ValueTypes::STRING] ?? null) instanceof DateTime ? ValueTypes::STRING : null,
            \is_bool($value) => ValueTypes::BOOLEAN,
            \is_array($value) && \array_is_list($value) => ValueTypes::ARRAY,
            default => null,
        };

        return $slot === null ? null : ($this->members[$slot] ?? null);
    }

    /**
     * The numeric member a number's PHP type names when the union holds both an
     * {@see Integer} and a {@see Decimal}: the `Integer` for an `int`, the `Decimal` for a
     * `float`. `null` for anything else, or when the union holds at most one of them.
     */
    private function numericMemberFor(mixed $value): ?AbstractFieldValue
    {
        if (!isset($this->members[ValueTypes::INTEGER], $this->members[ValueTypes::NUMBER])) {
            return null;
        }

        return match (true) {
            \is_int($value) => $this->members[ValueTypes::INTEGER],
            \is_float($value) => $this->members[ValueTypes::NUMBER],
            default => null,
        };
    }

    /**
     * The JSON type of each member, `integer` for an {@see Integer}, in member order.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return \array_map(self::valueTypeOf(...), $this->members());
    }

    /**
     * The member types joined by `|`, as `string|number|boolean|array`: the type a written
     * value must have, which {@see ValueTypes::matches()} reads.
     */
    public function valueType(): string
    {
        return \implode('|', $this->types());
    }

    public function withDefaultOutputTimezone(string $timezone): static
    {
        $members = \array_map(
            static fn(AbstractFieldValue $member): AbstractFieldValue => $member instanceof AcceptsDefaultOutputTimezoneInterface
                ? $member->withDefaultOutputTimezone($timezone)
                : $member,
            $this->members,
        );

        return $members === $this->members ? $this : new self($this->state, \array_values($members));
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $this->render($raw);
    }

    /**
     * Renders a stored value through the member its PHP type selects, or unchanged when
     * none does. {@see ArrayList} renders each element of a list of unions through it.
     *
     * @internal
     */
    public function render(mixed $raw): mixed
    {
        $member = $this->memberForStored($raw);

        return $member === null ? $raw : $member->serializeWithoutRequest([self::slot($member) => $raw]);
    }

    /**
     * The JSON-safe form a data layer stores a hydrated value in, such as a JSON column:
     * the member's wire form, so a `Date` member stores its date string, a `DateTime` or
     * `Time` member its formatted string, a member declared with `enum()` its case's backing
     * value, and a list each element's own. A plain string,
     * number or boolean comes back as its member renders it, and a value no member takes
     * unchanged. It reads back through {@see fromStoredForm()}, and a read renders it
     * as it renders the hydrated value. Storage that holds PHP values as they are, such as
     * an in-memory store, can keep the hydrated value instead.
     */
    public function toStoredForm(mixed $value): mixed
    {
        return $this->render($value);
    }

    /**
     * The hydrated value for a value {@see toStoredForm()} produced, cast as a write would
     * cast it, so a date member's string is a `\DateTimeImmutable` again and an `enum()`
     * member's backing value its case. A value no member
     * takes, or one the member cannot cast, comes back unchanged, since a read never fails
     * over what is stored.
     */
    public function fromStoredForm(mixed $stored): mixed
    {
        try {
            return $this->cast($stored, false, null, true);
        } catch (AttributeValueInvalid) {
            return $stored;
        }
    }

    public function hydrate(mixed $model, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        if ($this->hydratesThroughHookFor($creating)) {
            return parent::hydrate($model, $value, $data, $request, $creating);
        }

        $column = $this->state->column;
        if ($column === null) {
            return $model;
        }

        return Accessor::set($model, $column, $this->castAt($value, false, $request, $creating));
    }

    /**
     * Hydrates the value into a nested value, a key of an {@see Obj} or a {@see OneOf}
     * variant, where a date member stores a string as a nested {@see DateTime} does.
     *
     * @internal
     *
     * @param array<string, mixed> $data
     */
    public function hydrateNested(mixed $nested, mixed $value, array $data, JsonApiRequestInterface $request, bool $creating): mixed
    {
        $column = $this->state->column;
        if ($this->hydratesThroughHookFor($creating) || $column === null) {
            return $this->hydrate($nested, $value, $data, $request, $creating);
        }

        return Accessor::set($nested, $column, $this->castAt($value, true, $request, $creating));
    }

    /**
     * @internal
     */
    public function normalizeWireValue(mixed $value): mixed
    {
        $member = $this->memberFor($value);

        return $member === null ? $value : $member->normalizeWireValue($value);
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return $this->castAt($value, false, null, true);
    }

    /**
     * Casts a written value through the member its JSON type selects. A value no member
     * takes is returned as sent, for the adapter's validator to refuse, since hydration does
     * not validate. `$nested` stores a date member's value as a string and keeps a member
     * declared with `enum()` as its backing value rather than the case. A failing cast
     * throws with its pointer below the value, `''` for the value itself.
     *
     * @internal
     *
     * @throws AttributeValueInvalid
     */
    public function cast(mixed $value, bool $nested, ?JsonApiRequestInterface $request, bool $creating): mixed
    {
        $member = $this->memberFor($value);
        if ($member === null) {
            return $value;
        }

        if ($member instanceof ArrayList && \is_array($value)) {
            return $member->castUnionElements($value, $request, $creating, $nested);
        }

        try {
            if ($nested && $member instanceof DateTime && $request !== null) {
                return Accessor::get($member->hydrateNested([], $value, [], $request, $creating), self::slot($member));
            }

            return $nested ? $member->castNestedWireValue($value) : $member->castWireValueFor($value, $creating);
        } catch (AttributeValueInvalid $e) {
            $below = \str_starts_with($e->attribute, $member->name()) ? \substr($e->attribute, \strlen($member->name())) : '';

            throw $e->at(\ltrim($below, '/'));
        }
    }

    /**
     * Checks the value against the member its JSON type selects, so a date member's
     * string is refused in a shape the member does not document.
     *
     * @internal
     */
    public function assertWireFormat(mixed $value): void
    {
        $member = $this->memberFor($value);
        if ($member === null) {
            return;
        }

        try {
            $member->assertWireFormat($value);
        } catch (AttributeValueInvalid $e) {
            $below = self::pointerBelow($e, $member);

            throw $e->at($below === '' ? $this->name() : $this->name() . '/' . $below);
        }
    }

    /**
     * @throws AttributeValueInvalid
     */
    private function castAt(mixed $value, bool $nested, ?JsonApiRequestInterface $request, bool $creating): mixed
    {
        try {
            return $this->cast($value, $nested, $request, $creating);
        } catch (AttributeValueInvalid $e) {
            throw $e->at($e->attribute === '' ? $this->name() : $this->name() . '/' . $e->attribute);
        }
    }

    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        $schemas = \array_map(
            static fn(AbstractFieldValue $member): Schema => $projector->projectUnionMember($member, $creating, $collector),
            $this->members,
        );

        return self::compact($schemas) ?? Schema::create()->withOneOf(self::branches($schemas));
    }

    /**
     * One schema carrying every member's type and keywords, or `null` when a member
     * carries a keyword that is not scoped to its own type, such as `enum`, `const` or a
     * `$ref`, which would then constrain the other members' values too. An `Integer` and a
     * `Decimal` member list `number` once, since JSON Schema counts every integer as a
     * number, and only when they carry the same keywords, which then hold for both.
     *
     * @param array<string, Schema> $schemas the members' schemas, keyed as the members are
     */
    private static function compact(array $schemas): ?Schema
    {
        $integer = $schemas[ValueTypes::INTEGER] ?? null;
        $number = $schemas[ValueTypes::NUMBER] ?? null;
        if ($integer !== null && $number !== null) {
            if ($integer->extensions() !== [] || self::numericKeywords($integer) !== self::numericKeywords($number)) {
                return null;
            }
            unset($schemas[ValueTypes::INTEGER]);
        }

        $types = [];
        $keywords = [];
        foreach ($schemas as $schema) {
            $type = $schema->get('type');
            if (!\is_string($type) || !isset(self::TYPE_SCOPED[$type]) || \in_array($type, $types, true) || $schema->extensions() !== []) {
                return null;
            }
            foreach ($schema->keywords() as $keyword => $value) {
                if (!\in_array($keyword, self::TYPE_SCOPED[$type], true)) {
                    return null;
                }
                if ($keyword !== 'type') {
                    $keywords[$keyword] = $value;
                }
            }
            $types[] = $type;
        }

        return Schema::fromArray(['type' => $types, ...$keywords]);
    }

    /**
     * A numeric member's keywords less its `type`, sorted, each whole bound as a `float`,
     * so an `Integer`'s `minimum: 0` equals a `Decimal`'s `minimum: 0.0`.
     *
     * @return array<string, mixed>
     */
    private static function numericKeywords(Schema $schema): array
    {
        $keywords = \array_map(static fn(mixed $value): mixed => \is_int($value) ? (float) $value : $value, $schema->keywords());
        unset($keywords['type']);
        \ksort($keywords);

        return $keywords;
    }

    /**
     * The `oneOf` branches: one per member, except that an `Integer` and a `Decimal`
     * member share one `anyOf` branch. A whole number is both an `integer` and a `number`
     * to JSON Schema, which can't tell `500` from `500.0`, so two `oneOf` branches would
     * refuse it as matching both.
     *
     * @param array<string, Schema> $schemas
     *
     * @return list<Schema>
     */
    private static function branches(array $schemas): array
    {
        $integer = $schemas[ValueTypes::INTEGER] ?? null;
        $number = $schemas[ValueTypes::NUMBER] ?? null;
        if ($integer !== null && $number !== null) {
            $schemas[ValueTypes::NUMBER] = Schema::create()->withAnyOf([$integer, $number]);
            unset($schemas[ValueTypes::INTEGER]);
        }

        return \array_values($schemas);
    }

    /**
     * The JSON type a member's written value must have.
     */
    private static function valueTypeOf(FieldInterface $member): string
    {
        return ValueTypes::of($member) ?? ValueTypes::STRING;
    }

    private static function slot(FieldInterface $member): string
    {
        return $member->column() ?? $member->name();
    }

    /**
     * @param list<FieldInterface> $members
     *
     * @return array<string, AbstractFieldValue>
     *
     * @throws \LogicException
     */
    private static function keyed(string $name, array $members): array
    {
        $label = $name === '' ? 'An element Union' : \sprintf('Union "%s"', $name);
        if ($members === []) {
            throw new \LogicException(\sprintf('%s needs at least one member; declare them with of().', $label));
        }

        $keyed = [];
        foreach ($members as $member) {
            $slot = self::slotOf($member);
            if ($slot === null || !$member instanceof AbstractFieldValue) {
                throw new \LogicException(\sprintf(
                    '%s cannot take a %s member. A member is a string-family field, Integer or Decimal, Boolean, or an ArrayList of scalars or of a nested Union.',
                    $label,
                    (new \ReflectionClass($member))->getShortName(),
                ));
            }
            if (isset($keyed[$slot])) {
                throw new \LogicException(\sprintf(
                    '%s already has a member of JSON type %s, so it cannot take a second one: a union holds one member per JSON type, with an Integer and a Decimal the only pair, so each value has one member whose rules apply.',
                    $label,
                    $slot,
                ));
            }
            $problem = self::problemWith($member);
            if ($problem !== null) {
                throw new \LogicException(\sprintf('%s %s member %s.', $label, $slot, $problem));
            }
            $keyed[$slot] = $member;
        }

        $ordered = [];
        foreach ([ValueTypes::STRING, ValueTypes::INTEGER, ValueTypes::NUMBER, ValueTypes::BOOLEAN, ValueTypes::ARRAY] as $slot) {
            if (isset($keyed[$slot])) {
                $ordered[$slot] = $keyed[$slot];
            }
        }

        return $ordered;
    }

    /**
     * The JSON type a member takes, `integer` for an {@see Integer} and `number` for a
     * {@see Decimal}, or `null` for a field that cannot be a member.
     */
    private static function slotOf(FieldInterface $member): ?string
    {
        return match (true) {
            $member instanceof Str, $member instanceof DateTime => ValueTypes::STRING,
            $member instanceof Integer => ValueTypes::INTEGER,
            $member instanceof Decimal => ValueTypes::NUMBER,
            $member instanceof Boolean => ValueTypes::BOOLEAN,
            $member instanceof ArrayList && $member->element() === null => ValueTypes::ARRAY,
            default => null,
        };
    }

    /**
     * What makes a member more than a value shape: a hook, a visibility or presence rule,
     * or a source of its own. Those belong to the union.
     */
    private static function problemWith(AbstractFieldValue $member): ?string
    {
        foreach ($member->constraints() as $constraint) {
            if ($constraint instanceof Required || $constraint instanceof Nullable) {
                return 'cannot be required() or nullable(); presence belongs to the union';
            }
        }

        return match (true) {
            $member->rendersThroughHook() || $member->hydratesThroughHook() => 'cannot use its own serialize, extract, deserialize or fill hook; put the hook on the union',
            $member->isHidden() || $member->isWriteOnly() || $member->isReadOnly(true) || $member->isReadOnly(false) || $member->hasConditionalReadVisibility() => 'cannot be hidden, write-only or read-only; visibility belongs to the union',
            $member->relatedVia() !== null || $member->column() === null => 'needs no source of its own; the union reads and writes the value',
            default => null,
        };
    }

    public function componentName(): ?string
    {
        return $this->state->component;
    }
}
