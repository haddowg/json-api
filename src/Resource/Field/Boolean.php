<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A boolean attribute — the built, readonly value object the engine walks.
 * Authors declare one with {@see make()}, which returns a mutable
 * {@see BooleanBuilder}; the resource **builds** it into this value object before
 * use. Serializes/hydrates as `bool`.
 */
final readonly class Boolean extends AbstractFieldValue
{
    public static function make(string $name): BooleanBuilder
    {
        return new BooleanBuilder($name);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (bool) $raw;
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return \is_bool($value) ? $value : (bool) $value;
    }
}
