<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Shared serialization helpers for the OpenAPI 3.1 value objects.
 *
 * The OAS VOs serialize in two complementary forms (mirroring {@see Schema}):
 *
 *  - {@see toArray()} on each VO — the assertion-friendly nested-array form, which
 *    cannot distinguish an empty object `{}` from an empty list `[]` once flattened.
 *  - {@see toObject()} here — the JSON-faithful `stdClass` graph an encoder/validator
 *    consumes, where an **empty map** becomes an empty `stdClass` (a JSON object,
 *    never `[]`) and a **list** stays a JSON array. Nested {@see Schema} nodes and
 *    nested OAS VOs are converted recursively.
 *
 * VOs build their `toArray()` by **omitting absent/empty members** (so an empty
 * `[]`/`{}` is simply never present where the spec wants it omitted); where the
 * spec *requires* an object that may be empty (the `responses` map, `components`
 * sub-maps, `paths`), the VO emits an empty map and {@see toObject()} renders it as
 * `{}`, not `[]`.
 */
final class Serialization
{
    /**
     * Converts a VO-produced array to the JSON `stdClass` graph: an associative
     * (string-keyed, or empty) array becomes a `stdClass`; a list stays a JSON
     * array; nested {@see Schema}/VO instances and arrays recurse.
     *
     * @param array<array-key, mixed> $value
     */
    public static function toObject(array $value): \stdClass
    {
        $object = new \stdClass();
        foreach ($value as $key => $item) {
            $object->{$key} = self::convert($item);
        }

        return $object;
    }

    /**
     * Converts a single value to its JSON form.
     */
    public static function convert(mixed $value): mixed
    {
        if ($value instanceof Schema) {
            return $value->toJson();
        }

        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        if (\is_array($value)) {
            if (self::isList($value)) {
                return \array_map(static fn(mixed $item): mixed => self::convert($item), $value);
            }

            return self::toObject($value);
        }

        return $value;
    }

    /**
     * Whether an array should serialize as a JSON list. An empty array is treated
     * as a **map** (`{}`), because the VOs only ever emit an array where the spec
     * requires an object — an empty *list* member is omitted from `toArray()`
     * entirely, so it never reaches serialization.
     *
     * @param array<array-key, mixed> $value
     */
    private static function isList(array $value): bool
    {
        return $value !== [] && \array_is_list($value);
    }
}
