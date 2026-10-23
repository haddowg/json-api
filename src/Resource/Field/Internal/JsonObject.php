<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

use haddowg\JsonApi\Exception\AttributeValueInvalid;

/**
 * Keeps an object-typed field value a JSON object on the way out, and refuses a JSON
 * array for one on the way in.
 *
 * @internal
 */
final class JsonObject
{
    /**
     * `json_encode()` writes an empty or list-shaped PHP array as a JSON array, so
     * those become a `\stdClass`; any other array already encodes as an object and is
     * returned as is.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>|\stdClass
     */
    public static function of(array $value): array|\stdClass
    {
        return \array_is_list($value) ? (object) $value : $value;
    }

    /**
     * Throws when a decoded request value is a non-empty list. An empty array passes:
     * a body decoded to associative arrays turns both `[]` and `{}` into `[]`, so the
     * two can no longer be told apart here.
     *
     * @throws AttributeValueInvalid
     */
    public static function assertNotList(mixed $value, string $attribute): void
    {
        if (\is_array($value) && $value !== [] && \array_is_list($value)) {
            throw new AttributeValueInvalid($attribute, 'expected an object, got a list');
        }
    }

    /**
     * Runs a composite field's child hydration, moving a child's
     * {@see AttributeValueInvalid} under the parent so its pointer reads
     * `/data/attributes/<parent>/<child>`.
     *
     * @template T
     *
     * @param \Closure(): T $hydrate
     *
     * @return T
     *
     * @throws AttributeValueInvalid
     */
    public static function hydrateChildren(string $parent, \Closure $hydrate): mixed
    {
        try {
            return $hydrate();
        } catch (AttributeValueInvalid $e) {
            throw new AttributeValueInvalid($parent . '/' . $e->attribute, $e->reason);
        }
    }
}
