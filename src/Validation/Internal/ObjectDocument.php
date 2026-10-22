<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation\Internal;

/**
 * Decodes a JSON body for schema validation with JSON objects kept as objects.
 * An associative decode turns `{}` into `[]`, which opis then validates as an
 * array, so a valid empty object would fail a `type: object` check.
 *
 * @internal
 */
final class ObjectDocument
{
    /**
     * @throws \JsonException when `$json` is not well-formed
     */
    public static function decode(string $json): mixed
    {
        return \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
    }
}
