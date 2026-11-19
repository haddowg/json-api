<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\KeySelection;

/**
 * Trims an object attribute's value to the keys a request selected, and calls an
 * `extractUsing()` hook with them.
 *
 * @internal
 */
final class KeyTrim
{
    /**
     * The members of `$value` whose keys `$only` holds, each narrowed key trimmed to the
     * selection inside it. A value that is not an object is returned as it is.
     */
    public static function trim(mixed $value, KeySelection $only): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (\is_object($value)) {
            $value = \get_object_vars($value);
        }
        if (!\is_array($value)) {
            return $value;
        }

        $kept = [];
        foreach ($value as $key => $member) {
            if ($only->has((string) $key)) {
                $inner = $only->nested((string) $key);
                $kept[$key] = $inner === null ? $member : self::trim($member, $inner);
            }
        }

        return JsonObject::of($kept);
    }

    /**
     * Calls an `extractUsing()` hook, passing the selected keys as its fourth argument.
     */
    public static function extract(\Closure $hook, mixed $model, JsonApiRequestInterface $request, string $name, ?KeySelection $only): mixed
    {
        return $hook($model, $request, $name, $only);
    }
}
