<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * An integer attribute (JSON `type: integer`) — the built, readonly value object
 * the engine walks. Authors declare one with {@see make()}, which returns a
 * mutable {@see IntegerBuilder}; the resource **builds** it into this value object
 * before use. Serializes/hydrates as `int`.
 */
final readonly class Integer extends AbstractFieldValue
{
    public static function make(string $name): IntegerBuilder
    {
        return new IntegerBuilder($name);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (\is_numeric($raw) ? (int) $raw : $raw);
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return \is_numeric($value) ? (int) $value : $value;
    }
}
