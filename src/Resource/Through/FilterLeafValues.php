<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

use haddowg\JsonApi\Resource\Filter\ListValue;

/**
 * How a filter reaching through relations reads its requested value at the leaf: a
 * reached id compares by its storage key, so each wire id is decoded through the
 * reached type's encoder.
 *
 * @internal
 */
final class FilterLeafValues
{
    /**
     * One value, decoded when the leaf is an id.
     */
    public static function single(?RelationPath $path, string $key, mixed $value): mixed
    {
        return $path?->leafId()?->encoder() !== null ? $path->storageValue($key, $value) : $value;
    }

    /**
     * A list filter's members, decoded when the leaf is an id; the value unchanged otherwise.
     */
    public static function members(?RelationPath $path, string $key, mixed $value, ?ListValue $list, ?string $delimiter): mixed
    {
        if ($path?->leafId()?->encoder() === null) {
            return $value;
        }

        $members = $list?->members($value) ?? match (true) {
            \is_array($value) => \array_values($value),
            \is_string($value) => \array_map('\trim', \explode($delimiter !== null && $delimiter !== '' ? $delimiter : ',', $value)),
            default => [$value],
        };

        return \array_map(static fn(mixed $member): mixed => $path->storageValue($key, $member), $members);
    }
}
