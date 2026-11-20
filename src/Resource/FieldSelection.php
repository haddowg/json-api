<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\KeySelection;
use haddowg\JsonApi\Resource\Field\OneOf;
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
 * whose element declares `selectableKeys()`, as `<attr>.*.<key>`, and for nothing else. A
 * {@see OneOf}, wherever it appears, takes every key one of its variants accepts, each value
 * keeping its discriminator and the keys its own variant accepts. An
 * attribute or key the parameter also lists whole renders whole. A path ending in `*` names
 * the list itself, so `<attr>.*` lists the attribute whole exactly as `<attr>` does, and
 * narrows nothing.
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
     * selectable keys, accepts none of them, or they select it whole. A dotted key is a
     * path into a nested object: it is accepted when each object along it declares
     * `selectableKeys()` and accepts the next key, or is a list whose element does and the
     * key is `*`. A key also selected whole is not narrowed, and a path ending in `*`
     * selects its list whole.
     *
     * @param list<string> $keys
     */
    public static function selection(FieldInterface $field, array $keys, JsonApiRequestInterface $request): ?KeySelection
    {
        $selected = self::selectKeys($field, $keys, $request);

        return $selected instanceof KeySelection ? $selected : null;
    }

    /**
     * Whether the `$keys` select the field whole, as `*` alone does a list whose element
     * declares `selectableKeys()`. Such a field renders as if `fields[TYPE]` listed its
     * name.
     *
     * @param list<string> $keys
     */
    public static function selectsWhole(FieldInterface $field, array $keys, JsonApiRequestInterface $request): bool
    {
        return self::selectKeys($field, $keys, $request) === true;
    }

    /**
     * @param list<string> $keys
     */
    private static function selectKeys(FieldInterface $field, array $keys, JsonApiRequestInterface $request): KeySelection|true|null
    {
        return self::select($field, \array_map(static fn(string $key): array => \explode('.', $key), $keys), $request);
    }

    /**
     * The selection the paths make of the field, `true` when one of them selects the field
     * whole, or `null` when it accepts none of them.
     *
     * @param list<list<string>> $paths
     */
    private static function select(FieldInterface $field, array $paths, JsonApiRequestInterface $request): KeySelection|true|null
    {
        if ($field instanceof OneOf) {
            return self::selectVariants($field, $paths, $request);
        }
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
                // `*` alone names every item whole, which is the whole list.
                if ($field instanceof ArrayList) {
                    return true;
                }
                $selected[$key] = true;
                $whole[$key] = true;

                continue;
            }
            $inner = self::selectChild($field, $key, [$rest], $request);
            if ($inner === null) {
                continue;
            }
            $selected[$key] = true;
            if ($inner === true) {
                $whole[$key] = true;

                continue;
            }
            $deeper[$key][] = $rest;
        }

        if ($selected === []) {
            return null;
        }

        $nested = [];
        foreach (\array_diff_key($deeper, $whole) as $key => $rests) {
            $inner = self::selectChild($field, (string) $key, $rests, $request);
            if ($inner instanceof KeySelection) {
                $nested[(string) $key] = $inner;
            }
        }

        return new KeySelection(\array_map(\strval(...), \array_keys($selected)), $nested);
    }

    /**
     * The selection the paths make of the field rendering `$key`. The items of a list of a
     * {@see OneOf} accepts what any of its variants accepts.
     *
     * @param list<list<string>> $paths
     */
    private static function selectChild(SelectsKeysInterface $field, string $key, array $paths, JsonApiRequestInterface $request): KeySelection|true|null
    {
        $child = $field->keyField($key);

        return $child === null ? null : self::select($child, $paths, $request);
    }

    /**
     * What any variant of the union accepts of the paths, holding the discriminator too,
     * since every narrowed value renders it.
     *
     * @param list<list<string>> $paths
     */
    private static function selectVariants(OneOf $field, array $paths, JsonApiRequestInterface $request): ?KeySelection
    {
        $merged = null;
        foreach ($field->selectableVariants() as $variant) {
            $selection = self::select($variant, $paths, $request);
            if ($selection instanceof KeySelection) {
                $merged = $merged === null ? $selection : self::merge($merged, $selection);
            }
        }
        $discriminator = $field->discriminatorName();
        if ($merged === null || $discriminator === null || $merged->has($discriminator)) {
            return $merged;
        }

        return self::merge(new KeySelection([$discriminator]), $merged);
    }

    /**
     * Both selections at once: a key either selects whole is whole, and a key both narrow
     * holds both narrowings.
     */
    private static function merge(KeySelection $a, KeySelection $b): KeySelection
    {
        $keys = [...$a->keys, ...$b->keys];
        $nested = [];
        foreach ($keys as $key) {
            $inA = $a->has($key) ? $a->nested($key) ?? true : null;
            $inB = $b->has($key) ? $b->nested($key) ?? true : null;
            if ($inA === true || $inB === true) {
                continue;
            }
            $nested[$key] = $inA === null ? $inB : ($inB === null ? $inA : self::merge($inA, $inB));
        }

        return new KeySelection($keys, \array_filter($nested));
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
     * Whether `fields[$type]` selects the attribute `$name` whole through a key member, as
     * `<attr>.*` does a list, read against the type's Resource as
     * {@see attributeSelection()} reads it. An attribute listed by its name is not counted
     * here: {@see JsonApiRequestInterface::isIncludedField()} answers for it.
     */
    public static function attributeSelectedWhole(SerializerInterface $serializer, string $type, string $name, JsonApiRequestInterface $request, ?SerializerResolverInterface $resolver = null): bool
    {
        $keys = self::requestedKeys($request, $type)[$name] ?? null;
        $resource = $keys === null ? null : self::declaringResource($serializer, $type, $resolver);
        $field = $resource === null ? null : self::attributeNamed($resource, $name);

        return $field !== null && $keys !== null && self::selectsWhole($field, $keys, $request);
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

        return $field !== null && self::selectKeys($field, [\substr($member, $dot + 1)], $request) !== null;
    }

    /**
     * Whether the request narrows an attribute of any type the resolver serializes, so
     * its responses apply the field-selection profile. A key member that selects its
     * attribute whole, as `<attr>.*` does a list, narrows nothing.
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
