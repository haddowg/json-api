<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayList;
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
 * it. A key holds no `.`, so a longer path such as `<attr>.<key>.<key>` names a key of a
 * nested object, and is accepted only when every object along it declares
 * `selectableKeys()` accepting the next key. The key `*` stands for every item of a list
 * whose element declares `selectableKeys()`, as `<attr>.*.<key>`, and for nothing else. An
 * attribute or key the parameter also lists whole renders whole, and so does a list whose
 * items it lists whole as `<attr>.*`.
 */
final class FieldSelection
{
    /**
     * The keys `fields[$type]` requests per attribute it narrows rather than lists whole,
     * before any declaration has accepted them, each the rest of its member after the
     * attribute's name, such as `postcode` or `geo.lat`. Empty when the request has no
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
     * selectable keys or accepts none of them. A dotted key is a path into a nested
     * object: it is accepted when each object along it declares `selectableKeys()` and
     * accepts the next key, or is a list whose element does and the key is `*`. A key also
     * selected whole is not narrowed.
     *
     * @param list<string> $keys
     */
    public static function selection(FieldInterface $field, array $keys, JsonApiRequestInterface $request): ?KeySelection
    {
        return self::select($field, \array_map(static fn(string $key): array => \explode('.', $key), $keys), $request);
    }

    /**
     * @param list<list<string>> $paths
     */
    private static function select(FieldInterface $field, array $paths, JsonApiRequestInterface $request): ?KeySelection
    {
        $declared = $field instanceof SelectsKeysInterface ? $field->selectableKeys() : null;
        if ($declared === null || !$field instanceof SelectsKeysInterface) {
            return null;
        }

        $selected = [];
        $whole = [];
        $deeper = [];
        foreach ($paths as $segments) {
            $key = $segments[0];
            if (($key === KeySelection::ITEMS) !== $field instanceof ArrayList || !$declared->accepts($key, $request)) {
                continue;
            }
            $rest = \array_slice($segments, 1);
            if ($rest === []) {
                $selected[$key] = true;
                $whole[$key] = true;

                continue;
            }
            $child = $field->keyField($key);
            if ($child === null || self::select($child, [$rest], $request) === null) {
                continue;
            }
            $selected[$key] = true;
            $deeper[$key][] = $rest;
        }

        if ($selected === []) {
            return null;
        }

        $nested = [];
        foreach (\array_diff_key($deeper, $whole) as $key => $rests) {
            $child = $field->keyField((string) $key);
            $inner = $child === null ? null : self::select($child, $rests, $request);
            if ($inner !== null) {
                $nested[(string) $key] = $inner;
            }
        }

        return new KeySelection(\array_map(\strval(...), \array_keys($selected)), $nested);
    }

    /**
     * The selection `fields[$type]` makes of the attribute `$name` of a resource the
     * serializer renders, read against the type's Resource, or `null` when the request
     * does not narrow that attribute or its declaration accepts none of the keys. A
     * serializer that is not the type's Resource, such as an override, is narrowed by the
     * fields of the Resource the type was registered with, which `$resolver` provides.
     */
    public static function attributeSelection(SerializerInterface $serializer, string $type, string $name, JsonApiRequestInterface $request, ?SerializerResolverInterface $resolver = null): ?KeySelection
    {
        $keys = self::requestedKeys($request, $type)[$name] ?? null;
        $resource = $keys === null ? null : self::declaringResource($serializer, $type, $resolver);
        $field = $resource === null ? null : self::attributeNamed($resource, $name);

        return $field === null || $keys === null ? null : self::selection($field, $keys, $request);
    }

    /**
     * Whether a dotted `fields[TYPE]` member names a key the serializer's attribute
     * accepts. A hidden or write-only attribute counts, as its plain name does, so the
     * answer gives nothing away about it. A serializer that is not a Resource is read
     * against the Resource `$type` was registered with, which `$resolver` provides.
     */
    public static function recognizes(SerializerInterface $serializer, string $member, JsonApiRequestInterface $request, ?SerializerResolverInterface $resolver = null, ?string $type = null): bool
    {
        $dot = \strpos($member, '.');
        $resource = $dot === false ? null : self::declaringResource($serializer, $type, $resolver);
        if ($dot === false || $resource === null) {
            return false;
        }

        $field = self::attributeNamed($resource, \substr($member, 0, $dot));

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
            $resource = self::declaringResource($resolver->serializerFor($type), $type, $resolver);
            if ($resource === null) {
                continue;
            }
            foreach (self::requestedKeys($request, $type) as $name => $keys) {
                $field = self::attributeNamed($resource, $name);
                if ($field !== null && self::selection($field, $keys, $request) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The Resource whose fields decide what `fields[$type]` selects: the serializer when it
     * is one, else the Resource the type was registered with, looked up through the
     * serializer's own resolver and then `$resolver`. `null` for a bare serializer.
     */
    private static function declaringResource(SerializerInterface $serializer, ?string $type, ?SerializerResolverInterface $resolver): ?AbstractResource
    {
        if ($serializer instanceof AbstractResource) {
            return $serializer;
        }
        if ($type === null) {
            return null;
        }

        $own = $serializer instanceof SerializerResolverAwareInterface ? $serializer->serializerResolver() : null;
        foreach ([$own, $resolver] as $candidate) {
            if ($candidate instanceof ProvidesResourcesInterface && $candidate->hasResourceFor($type)) {
                return $candidate->resourceFor($type);
            }
        }

        return null;
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
