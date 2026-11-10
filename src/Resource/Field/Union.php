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
 * names the one member whose rules apply and whose PHP type comes back. There is no
 * discriminator.
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
     * The members, keyed by the JSON type each takes, with `number` standing for both
     * {@see Integer} and {@see Decimal}, in the order string, number, boolean, array.
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

    public static function make(string $name): UnionBuilder
    {
        return new UnionBuilder($name);
    }

    /**
     * An unnamed union of `$members`, for a list's elements:
     * `ArrayList::make('values')->of(Union::of(Str::make(), Decimal::make()))`.
     */
    public static function of(FieldInterface|FieldBuilderInterface ...$members): UnionBuilder
    {
        return (new UnionBuilder(''))->of(...$members);
    }

    /**
     * The members, in the order string, number, boolean, array.
     *
     * @return list<AbstractFieldValue>
     */
    public function members(): array
    {
        return \array_values($this->members);
    }

    /**
     * The member a written value's JSON type selects, or `null` when no member takes that
     * type, which a write refuses. `null` selects no member.
     */
    public function memberFor(mixed $value): ?AbstractFieldValue
    {
        if ($value === null) {
            return null;
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
     * number member, a bool to the boolean member and a list to the array member. A backed
     * enum goes by its value. `null` when no member takes the value.
     */
    public function memberForStored(mixed $value): ?AbstractFieldValue
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        $slot = match (true) {
            \is_string($value) => ValueTypes::STRING,
            $value instanceof \DateTimeInterface => ($this->members[ValueTypes::STRING] ?? null) instanceof DateTime ? ValueTypes::STRING : null,
            \is_int($value), \is_float($value) => ValueTypes::NUMBER,
            \is_bool($value) => ValueTypes::BOOLEAN,
            \is_array($value) && \array_is_list($value) => ValueTypes::ARRAY,
            default => null,
        };

        return $slot === null ? null : ($this->members[$slot] ?? null);
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
     * `Time` member its formatted string, and a list each element's own. A plain string,
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
     * cast it, so a date member's string is a `\DateTimeImmutable` again. A value no member
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
        if ($this->hydratesThroughHook()) {
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
        if ($this->hydratesThroughHook() || $column === null) {
            return $this->hydrate($nested, $value, $data, $request, $creating);
        }

        return Accessor::set($nested, $column, $this->castAt($value, true, $request, $creating));
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return $this->castAt($value, false, null, true);
    }

    /**
     * Casts a written value through the member its JSON type selects. A value no member
     * takes is returned as sent, for the adapter's validator to refuse, since hydration does
     * not validate. `$nested` stores a date member's value as a string. A failing cast
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
            return $member->castUnionElements($value, $request, $creating);
        }

        try {
            if ($nested && $member instanceof DateTime && $request !== null) {
                return Accessor::get($member->hydrateNested([], $value, [], $request, $creating), self::slot($member));
            }

            return $member->castWireValue($value);
        } catch (AttributeValueInvalid $e) {
            $below = \str_starts_with($e->attribute, $member->name()) ? \substr($e->attribute, \strlen($member->name())) : '';

            throw new AttributeValueInvalid(\ltrim($below, '/'), $e->reason);
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
            throw new AttributeValueInvalid($e->attribute === '' ? $this->name() : $this->name() . '/' . $e->attribute, $e->reason);
        }
    }

    public function projectFieldSchema(SchemaProjector $projector, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        $schemas = \array_map(
            static fn(AbstractFieldValue $member): Schema => $projector->projectUnionMember($member, $creating, $collector),
            $this->members(),
        );

        return self::compact($schemas) ?? Schema::create()->withOneOf($schemas);
    }

    /**
     * One schema carrying every member's type and keywords, or `null` when a member
     * carries a keyword that is not scoped to its own type, such as `enum`, `const` or a
     * `$ref`, which would then constrain the other members' values too.
     *
     * @param list<Schema> $schemas
     */
    private static function compact(array $schemas): ?Schema
    {
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
                    '%s already has a %s member, so it cannot take a second one: a union holds one member per JSON type, so each value has one member whose rules apply.',
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
        foreach ([ValueTypes::STRING, ValueTypes::NUMBER, ValueTypes::BOOLEAN, ValueTypes::ARRAY] as $slot) {
            if (isset($keyed[$slot])) {
                $ordered[$slot] = $keyed[$slot];
            }
        }

        return $ordered;
    }

    /**
     * The JSON type a member takes, `number` for both numeric fields, or `null` for a field
     * that cannot be a member.
     */
    private static function slotOf(FieldInterface $member): ?string
    {
        return match (true) {
            $member instanceof Str, $member instanceof DateTime => ValueTypes::STRING,
            $member instanceof Integer, $member instanceof Decimal => ValueTypes::NUMBER,
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
