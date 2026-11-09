<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Finds every {@see Obj} value in a write body's `data.attributes` that is open to
 * undeclared members or bounds how many members it carries, at any depth: an attribute,
 * a child of an `Obj`, `Map` or `OneOf` variant, a structured list element, or the value
 * of another open object's member. Validating them is the adapter's job; this walk is
 * where they are, so every adapter reaches the same values with the same pointers.
 *
 * It follows what hydration writes. A field hidden, or read-only for the caller, is not
 * walked, and neither is the inside of a field whose `fillUsing()` or `deserializeUsing()`
 * hook takes the value, though such an object's own member count still is. A value of the
 * wrong structure is skipped, since hydration reports it.
 */
final class ObjectValues
{
    /**
     * @param iterable<FieldInterface> $attributeFields the resource's fields
     * @param array<array-key, mixed>  $attributes      the body's `data.attributes`
     *
     * @return list<ObjectValue>
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
     * @param list<ObjectValue> $found
     */
    private static function field(FieldInterface $field, mixed $value, string $pointer, bool $creating, JsonApiRequestInterface $request, array &$found): void
    {
        if ($field->isHidden() || $field->isReadOnlyFor($creating, $request) || !\is_array($value)) {
            return;
        }
        $hooked = $field instanceof AbstractFieldValue && $field->hydratesThroughHook();

        if ($field instanceof ArrayList) {
            $element = $field->element();
            if ($hooked || $element === null || !\array_is_list($value)) {
                return;
            }
            foreach ($value as $index => $item) {
                self::field($element, $item, $pointer . '/' . $index, $creating, $request, $found);
            }

            return;
        }

        if ($value !== [] && \array_is_list($value)) {
            return;
        }

        if ($field instanceof Obj) {
            self::object($field, $value, $pointer, $hooked, $creating, $request, $found);

            return;
        }

        if ($hooked) {
            return;
        }
        if ($field instanceof Map) {
            self::children($field->children(), $value, $pointer, $creating, $request, $found);
        }
        if ($field instanceof OneOf) {
            $variant = $field->variantOf($value);
            if ($variant !== null) {
                self::children($variant->children(), $value, $pointer, $creating, $request, $found);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<ObjectValue>       $found
     */
    private static function object(Obj $object, array $value, string $pointer, bool $hooked, bool $creating, JsonApiRequestInterface $request, array &$found): void
    {
        $memberField = $object->additionalProperties();
        $writesMembers = !$hooked && $memberField !== null && !$memberField->isHidden() && !$memberField->isReadOnlyFor($creating, $request);
        $additional = $writesMembers ? $object->additionalMembersOf($value) : [];

        if ($memberField !== null || self::countsMembers($object)) {
            $found[] = new ObjectValue($pointer, $object, $value, $additional, $writesMembers ? $memberField : null);
        }
        if ($hooked) {
            return;
        }

        self::children($object->children(), $value, $pointer, $creating, $request, $found);
        if ($writesMembers) {
            foreach ($additional as $key => $member) {
                self::field($memberField, $member, $pointer . '/' . JsonObject::pointerSegment($key), $creating, $request, $found);
            }
        }
    }

    /**
     * @param iterable<FieldInterface> $children
     * @param array<array-key, mixed>  $value
     * @param list<ObjectValue>        $found
     */
    private static function children(iterable $children, array $value, string $pointer, bool $creating, JsonApiRequestInterface $request, array &$found): void
    {
        foreach ($children as $child) {
            if (\array_key_exists($child->name(), $value)) {
                self::field($child, $value[$child->name()], $pointer . '/' . JsonObject::pointerSegment($child->name()), $creating, $request, $found);
            }
        }
    }

    private static function countsMembers(Obj $object): bool
    {
        foreach ($object->constraints() as $constraint) {
            if ($constraint instanceof MinProperties || $constraint instanceof MaxProperties) {
                return true;
            }
        }

        return false;
    }
}
