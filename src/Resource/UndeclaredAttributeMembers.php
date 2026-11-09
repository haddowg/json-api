<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;

/**
 * Finds the members of a write body's `data.attributes` that the resource does not
 * declare: an attribute no field names, and a key inside an `Obj`, `Map`, `OneOf`
 * variant or structured list element that none of its children name. A declared shape
 * is closed, so each one is an error, not data. An `Obj` declaring
 * `additionalProperties()` is the exception: it declares every such key, and each one's
 * value is walked as that field's.
 *
 * Only declared structure is walked. A field whose `fillUsing()` or `deserializeUsing()`
 * hook takes the value is walked like any other, since the hook decides how the value is
 * stored, not which members it may carry. A `OneOf` value naming no variant is left to
 * the validator that reports the unknown variant. A declared member that is read-only
 * for the caller is not undeclared: whether it is ignored or refused is
 * {@see RejectsReadOnlyAttributesInterface}'s concern.
 */
final class UndeclaredAttributeMembers
{
    /**
     * The JSON Pointer of every undeclared member, in body order.
     *
     * @param iterable<FieldInterface> $attributeFields the resource's attribute fields, hidden ones excluded
     * @param array<array-key, mixed>  $attributes      the body's `data.attributes`
     *
     * @return list<string>
     */
    public static function in(iterable $attributeFields, array $attributes): array
    {
        $pointers = [];
        self::walk(self::byName($attributeFields), $attributes, '/data/attributes', $pointers, []);

        return $pointers;
    }

    /**
     * @param array<string, FieldInterface> $declared
     * @param array<array-key, mixed>       $value
     * @param list<string>                  $pointers
     * @param list<string>                  $alsoAllowed members declared by the parent itself, as a `OneOf`'s discriminator
     * @param array<string, mixed>          $additional  the members an open object takes through `$memberField`
     */
    private static function walk(array $declared, array $value, string $path, array &$pointers, array $alsoAllowed, array $additional = [], ?FieldInterface $memberField = null): void
    {
        foreach ($value as $key => $member) {
            $key = (string) $key;
            $pointer = $path . '/' . JsonObject::pointerSegment($key);
            if (\in_array($key, $alsoAllowed, true)) {
                continue;
            }
            $field = $declared[$key] ?? null;
            if ($field === null && $memberField !== null && \array_key_exists($key, $additional)) {
                $field = $memberField;
            }
            if ($field === null) {
                $pointers[] = $pointer;

                continue;
            }
            self::descend($field, $member, $pointer, $pointers);
        }
    }

    /**
     * @param list<string> $pointers
     */
    private static function descend(FieldInterface $field, mixed $value, string $path, array &$pointers): void
    {
        if (!\is_array($value)) {
            return;
        }

        if ($field instanceof ArrayList) {
            $element = $field->element();
            if ($element === null || !\array_is_list($value)) {
                return;
            }
            foreach ($value as $index => $item) {
                self::descend($element, $item, $path . '/' . $index, $pointers);
            }

            return;
        }

        if (\array_is_list($value) && $value !== []) {
            // A list where an object is declared is a value error the field reports itself.
            return;
        }

        if ($field instanceof Obj) {
            // An open object declares every member its member field takes, unless that
            // field is hidden.
            $memberField = $field->additionalProperties();
            $memberField = $memberField === null || $memberField->isHidden() ? null : $memberField;
            self::walk(self::byName($field->children()), $value, $path, $pointers, [], $memberField === null ? [] : $field->additionalMembersOf($value), $memberField);

            return;
        }

        if ($field instanceof Map) {
            self::walk(self::byName($field->children()), $value, $path, $pointers, []);

            return;
        }

        if ($field instanceof OneOf) {
            $variant = $field->variantOf($value);
            $discriminator = $field->discriminatorName();
            if ($variant !== null) {
                self::walk(self::byName($variant->children()), $value, $path, $pointers, $discriminator === null ? [] : [$discriminator]);
            }
        }
    }

    /**
     * @param iterable<FieldInterface> $fields
     *
     * @return array<string, FieldInterface>
     */
    private static function byName(iterable $fields): array
    {
        $byName = [];
        foreach ($fields as $field) {
            if (!$field->isHidden()) {
                $byName[$field->name()] = $field;
            }
        }

        return $byName;
    }
}
