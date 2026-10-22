<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

/**
 * Keeps an object-typed field value a JSON object once encoded. `json_encode()`
 * writes an empty or list-shaped PHP array as a JSON array, so those become a
 * `\stdClass`; any other array already encodes as an object and is returned as is.
 *
 * @internal
 */
final class JsonObject
{
    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>|\stdClass
     */
    public static function of(array $value): array|\stdClass
    {
        return \array_is_list($value) ? (object) $value : $value;
    }
}
