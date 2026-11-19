<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\KeySelection;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\SelectsKeysInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * Reads the `<attr>.<key>` members of a `fields[TYPE]` parameter against the attributes
 * that declare `selectableKeys()`. The resource renders through it, the server validates
 * through it and the responses advertise the field-selection profile through it, so an
 * adapter that needs the same answers asks here rather than parsing the members itself.
 *
 * A member is `<attr>.<key>`: the attribute name up to the first `.` and the key after
 * it, taken whole. An attribute the parameter also lists whole renders whole.
 */
final class FieldSelection
{
    /**
     * The keys `fields[$type]` requests per attribute it narrows rather than lists whole,
     * before any declaration has accepted them. Empty when the request has no
     * `fields[$type]`, or one selecting no fields.
     *
     * @return array<string, list<string>>
     */
    public static function requestedKeys(JsonApiRequestInterface $request, string $type): array
    {
        $whole = [];
        $keys = [];
        foreach ($request->getIncludedFields($type) as $member) {
            if ($member === '') {
                return [];
            }
            $dot = \strpos($member, '.');
            if ($dot === false) {
                $whole[$member] = true;

                continue;
            }
            $keys[\substr($member, 0, $dot)][] = \substr($member, $dot + 1);
        }

        return \array_diff_key($keys, $whole);
    }

    /**
     * The `$keys` the field's declaration accepts, or `null` when the field declares no
     * selectable keys or accepts none of them.
     *
     * @param list<string> $keys
     */
    public static function selection(FieldInterface $field, array $keys, JsonApiRequestInterface $request): ?KeySelection
    {
        $declared = $field instanceof SelectsKeysInterface ? $field->selectableKeys() : null;
        if ($declared === null) {
            return null;
        }

        $accepted = \array_values(\array_filter($keys, static fn(string $key): bool => $declared->accepts($key, $request)));

        return $accepted === [] ? null : new KeySelection($accepted);
    }

    /**
     * Whether a dotted `fields[TYPE]` member names a key the serializer's attribute
     * accepts. A hidden or write-only attribute counts, as its plain name does, so the
     * answer gives nothing away about it.
     */
    public static function recognizes(SerializerInterface $serializer, string $member, JsonApiRequestInterface $request): bool
    {
        $dot = \strpos($member, '.');
        if ($dot === false || !$serializer instanceof AbstractResource) {
            return false;
        }

        $field = self::attributeNamed($serializer, \substr($member, 0, $dot));

        return $field !== null && self::selection($field, [\substr($member, $dot + 1)], $request) !== null;
    }

    /**
     * Whether the request narrows an attribute of any type the resolver serializes, so
     * its responses apply the field-selection profile.
     */
    public static function narrows(SerializerResolverInterface $resolver, JsonApiRequestInterface $request): bool
    {
        foreach ($request->requestedFieldsetTypes() as $type) {
            if (!$resolver->hasSerializerFor($type)) {
                continue;
            }
            $serializer = $resolver->serializerFor($type);
            if (!$serializer instanceof AbstractResource) {
                continue;
            }
            foreach (self::requestedKeys($request, $type) as $name => $keys) {
                $field = self::attributeNamed($serializer, $name);
                if ($field !== null && self::selection($field, $keys, $request) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function attributeNamed(AbstractResource $resource, string $name): ?FieldInterface
    {
        foreach ($resource->allFields() as $field) {
            if ($field->name() === $name && !$field instanceof Id && !$field instanceof RelationInterface) {
                return $field;
            }
        }

        return null;
    }
}
