<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Union;

/**
 * Finds every place in a write body where a {@see Union}'s member applies, at any depth:
 * a union attribute, a union child of an `Obj`, `Map` or `OneOf` variant, the member field
 * of an open object, each element of a list of unions, and each element of a union's own
 * list member. For each it gives the pointer and the member the value's JSON type selects,
 * so every adapter makes the same value-dependent choice and only translates that member's
 * constraints at that pointer, as it translates any field's.
 *
 * It follows what a write may carry, as {@see ObjectValues} does: a field hidden, or
 * read-only for the caller, is not walked, and a field whose write hook takes the value is
 * walked like any other. A `null`, and a value no member takes, is skipped, since presence
 * and {@see ValueTypes} report those.
 */
final class UnionValues
{
    /**
     * @param iterable<FieldInterface> $attributeFields the resource's fields
     * @param array<array-key, mixed>  $attributes      the body's `data.attributes`
     *
     * @return list<UnionValue>
     */
    public static function in(iterable $attributeFields, array $attributes, bool $creating, JsonApiRequestInterface $request): array
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
     * The places in one written value, such as a pivot field's in a linkage member's
     * `meta`, the value itself at `$pointer`. Without a request, a field's read-only gate
     * and `when()` conditions are judged as they are for a write no request is known for.
     *
     * @return list<UnionValue>
     */
    public static function of(FieldInterface $field, mixed $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request = null): array
    {
        $found = [];
        self::field($field, $value, $pointer, $creating, $request, $found);

        return $found;
    }

    /**
     * @param list<UnionValue> $found
     */
    private static function field(FieldInterface $field, mixed $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found): void
    {
        if ($value === null || $field->isHidden() || ($request === null ? $field->isReadOnly($creating) : $field->isReadOnlyFor($creating, $request))) {
            return;
        }

        if ($field instanceof Union) {
            self::union($field, $value, $pointer, $creating, $request, $found);

            return;
        }

        if (!\is_array($value)) {
            return;
        }

        if ($field instanceof ArrayList) {
            $element = $field->element() ?? $field->elementUnion();
            if ($element !== null && \array_is_list($value)) {
                foreach ($value as $index => $item) {
                    self::field($element, $item, $pointer . '/' . $index, $creating, $request, $found);
                }
            }

            return;
        }

        if ($value !== [] && \array_is_list($value)) {
            return;
        }

        match (true) {
            $field instanceof Obj => self::object($field, $value, $pointer, $creating, $request, $found),
            $field instanceof Map => self::children($field->children(), $value, $pointer, $creating, $request, $found),
            $field instanceof OneOf => self::children($field->variantOf($value)?->children() ?? [], $value, $pointer, $creating, $request, $found),
            default => null,
        };
    }

    /**
     * @param list<UnionValue> $found
     */
    private static function union(Union $union, mixed $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found): void
    {
        $member = $union->memberFor($value);
        if ($member === null) {
            return;
        }

        $empties = \in_array(ValueTypes::of($member), [ValueTypes::STRING, ValueTypes::ARRAY], true);
        $found[] = new UnionValue($pointer, $union, $member, $value, $empties && self::isRequired($union, $value, $creating, $request));

        $element = $member instanceof ArrayList ? $member->elementUnion() : null;
        if ($element !== null && \is_array($value)) {
            foreach (\array_values($value) as $index => $item) {
                self::field($element, $item, $pointer . '/' . $index, $creating, $request, $found);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<UnionValue>        $found
     */
    private static function object(Obj $object, array $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found): void
    {
        self::children($object->children(), $value, $pointer, $creating, $request, $found);

        $memberField = $object->additionalProperties();
        if ($memberField === null) {
            return;
        }
        foreach ($object->additionalMembersOf($value) as $key => $member) {
            self::field($memberField, $member, $pointer . '/' . JsonObject::pointerSegment($key), $creating, $request, $found);
        }
    }

    /**
     * @param iterable<FieldInterface> $children
     * @param array<array-key, mixed>  $value
     * @param list<UnionValue>         $found
     */
    private static function children(iterable $children, array $value, string $pointer, bool $creating, ?JsonApiRequestInterface $request, array &$found): void
    {
        foreach ($children as $child) {
            if (\array_key_exists($child->name(), $value)) {
                self::field($child, $value[$child->name()], $pointer . '/' . JsonObject::pointerSegment($child->name()), $creating, $request, $found);
            }
        }
    }

    /**
     * Whether the union is required in this write: a {@see Required} that applies, or one
     * inside a {@see When} whose condition holds for the value and the request.
     */
    private static function isRequired(Union $union, mixed $value, bool $creating, ?JsonApiRequestInterface $request): bool
    {
        foreach ($union->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            if ($constraint instanceof Required) {
                return true;
            }
            if ($constraint instanceof When && ($constraint->condition)($value, $request) === true) {
                foreach ($constraint->constraints as $inner) {
                    if ($inner instanceof Required && $inner->context()->appliesTo($creating)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
