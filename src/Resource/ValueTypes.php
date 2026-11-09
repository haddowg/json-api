<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\ProvidesFieldSchema;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The JSON type each written value must have, and the values in a write body that do not
 * have it. A field's type is the one its write schema documents: `integer` for an
 * {@see Integer}, `number` for a {@see Decimal}, `boolean` for a {@see Boolean}, `array`
 * for an {@see ArrayList}, `object` for an {@see Obj}, {@see OneOf}, {@see Map} or
 * {@see ArrayHash}, and `string` for everything else, dates and enums included.
 *
 * A field's cast does not check the type, so an adapter validates every write against
 * {@see mismatchesIn()} before hydrating it and reports each mismatch as the only
 * violation at its pointer. The walk reaches every depth: an attribute, a child of an
 * `Obj`, `Map` or `OneOf` variant, an undeclared member of an open object, and each
 * element of a list. `null` matches only a field declared nullable in the write's context.
 * Like {@see ObjectValues} it follows what a write may carry, so a field hidden, or
 * read-only for the caller, is skipped, and a field whose `fillUsing()` or
 * `deserializeUsing()` hook takes the value is checked like any other. A list sent for an
 * object, and an object sent for a list of objects, are left to hydration, which refuses
 * them with its own error.
 */
final class ValueTypes
{
    public const string STRING = 'string';
    public const string INTEGER = 'integer';
    public const string NUMBER = 'number';
    public const string BOOLEAN = 'boolean';
    public const string OBJECT = 'object';
    public const string ARRAY = 'array';

    /**
     * The JSON type a written value of the field must have, or `null` for a field that
     * supplies its own schema and so has no type core can name.
     */
    public static function of(FieldInterface $field): ?string
    {
        return match (true) {
            $field instanceof RelationInterface => null,
            $field instanceof Integer => self::INTEGER,
            $field instanceof Decimal => self::NUMBER,
            $field instanceof Boolean => self::BOOLEAN,
            $field instanceof ArrayList => self::ARRAY,
            $field instanceof ArrayHash, $field instanceof Map, $field instanceof Obj, $field instanceof OneOf => self::OBJECT,
            $field instanceof ProvidesFieldSchema => null,
            default => self::STRING,
        };
    }

    /**
     * Whether a decoded JSON value has the JSON type. An integer is any whole number PHP
     * can hold as an `int`, so `1.0` is one. A body decoded to arrays turns both `{}` and
     * `[]` into `[]`, so an empty array is both an object and an array.
     */
    public static function matches(string $type, mixed $value): bool
    {
        return match ($type) {
            self::STRING => \is_string($value),
            self::BOOLEAN => \is_bool($value),
            self::INTEGER => \is_int($value) || (\is_float($value) && self::isWhole($value)),
            self::NUMBER => \is_int($value) || \is_float($value),
            self::OBJECT => $value instanceof \stdClass || (\is_array($value) && ($value === [] || !\array_is_list($value))),
            self::ARRAY => \is_array($value) && \array_is_list($value),
            default => true,
        };
    }

    /**
     * Every value in a write body's `data.attributes` whose JSON type its field does not
     * take.
     *
     * @param iterable<FieldInterface> $attributeFields the resource's fields
     * @param array<array-key, mixed>  $attributes      the body's `data.attributes`
     *
     * @return list<ValueTypeMismatch>
     */
    public static function mismatchesIn(iterable $attributeFields, array $attributes, bool $creating, JsonApiRequestInterface $request): array
    {
        $found = [];
        foreach ($attributeFields as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface || !\array_key_exists($field->name(), $attributes)) {
                continue;
            }
            self::field($field, $attributes[$field->name()], '/data/attributes/' . JsonObject::pointerSegment($field->name()), $creating, $request, $found);
        }

        return $found;
    }

    /**
     * Every value in one written value, such as a pivot field's in a linkage member's
     * `meta`, whose JSON type its field does not take, the value itself at `$pointer`.
     * Without a request, a field's read-only gate and `when()` conditions are judged as
     * they are for a write no request is known for.
     *
     * @return list<ValueTypeMismatch>
     */
    public static function mismatchesOf(FieldInterface $field, mixed $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request = null): array
    {
        $found = [];
        self::field($field, $value, $pointer, $creating, $request, $found);

        return $found;
    }

    /**
     * @param list<ValueTypeMismatch> $found
     */
    private static function field(FieldInterface $field, mixed $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found, bool $element = false, bool $hooked = false): void
    {
        if ($field->isHidden() || ($request === null ? $field->isReadOnly($creating) : $field->isReadOnlyFor($creating, $request))) {
            return;
        }

        $type = self::of($field);
        if ($type === null) {
            return;
        }

        if ($value === null) {
            // A list never holds null in place of an element, whatever the element declares.
            if ($element || !self::isNullable($field, $creating, $request)) {
                $found[] = new ValueTypeMismatch($pointer, $field, $type, $value);
            }

            return;
        }

        $hooked = $hooked || ($field instanceof AbstractFieldValue && $field->hydratesThroughHook());
        if (!self::matches($type, $value)) {
            if ($hooked || !self::refusedByHydration($field, $type, $value)) {
                $found[] = new ValueTypeMismatch($pointer, $field, $type, $value);
            }

            return;
        }

        if (!\is_array($value)) {
            return;
        }

        match (true) {
            $field instanceof ArrayList && \array_is_list($value) => self::elements($field, $value, $pointer, $creating, $request, $found, $hooked),
            $field instanceof Obj => self::object($field, $value, $pointer, $creating, $request, $found, $hooked),
            $field instanceof Map => self::children($field->children(), $value, $pointer, $creating, $request, $found, $hooked),
            $field instanceof OneOf => self::children($field->variantOf($value)?->children() ?? [], $value, $pointer, $creating, $request, $found, $hooked),
            default => null,
        };
    }

    /**
     * @param list<mixed>             $list
     * @param list<ValueTypeMismatch> $found
     */
    private static function elements(ArrayList $field, array $list, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found, bool $hooked): void
    {
        $element = $field->element();
        foreach ($list as $index => $item) {
            $itemPointer = $pointer . '/' . $index;
            if ($element !== null) {
                self::field($element, $item, $itemPointer, $creating, $request, $found, element: true, hooked: $hooked);

                continue;
            }
            if (!self::matches($field->elementType(), $item)) {
                $found[] = new ValueTypeMismatch($itemPointer, $field, $field->elementType(), $item);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<ValueTypeMismatch> $found
     */
    private static function object(Obj $object, array $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found, bool $hooked): void
    {
        self::children($object->children(), $value, $pointer, $creating, $request, $found, $hooked);

        $memberField = $object->additionalProperties();
        if ($memberField === null) {
            return;
        }
        foreach ($object->additionalMembersOf($value) as $key => $member) {
            self::field($memberField, $member, $pointer . '/' . JsonObject::pointerSegment($key), $creating, $request, $found, hooked: $hooked);
        }
    }

    /**
     * @param iterable<FieldInterface> $children
     * @param array<array-key, mixed>  $value
     * @param list<ValueTypeMismatch>  $found
     */
    private static function children(iterable $children, array $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found, bool $hooked): void
    {
        foreach ($children as $child) {
            if (\array_key_exists($child->name(), $value)) {
                self::field($child, $value[$child->name()], $pointer . '/' . JsonObject::pointerSegment($child->name()), $creating, $request, $found, hooked: $hooked);
            }
        }
    }

    /**
     * Whether the field takes `null` in this write: a {@see Nullable} that applies, or one
     * inside a {@see When} whose condition holds for `null` and the request.
     */
    private static function isNullable(FieldInterface $field, bool $creating, ?JsonApiRequestInterface $request): bool
    {
        foreach ($field->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            if ($constraint instanceof Nullable) {
                return true;
            }
            if ($constraint instanceof When && ($constraint->condition)(null, $request) === true) {
                foreach ($constraint->constraints as $inner) {
                    if ($inner instanceof Nullable && $inner->context()->appliesTo($creating)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether hydration already answers the value with its own `ATTRIBUTE_VALUE_INVALID`:
     * a list sent for an object, or an object sent for a list of objects. Under a write
     * hook nothing is hydrated, so nothing is refused there.
     */
    private static function refusedByHydration(FieldInterface $field, string $type, mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        return $type === self::OBJECT || ($field instanceof ArrayList && $field->element() !== null);
    }

    private static function isWhole(float $value): bool
    {
        return \is_finite($value) && \floor($value) === $value && $value >= \PHP_INT_MIN && $value < \PHP_INT_MAX;
    }
}
