<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A floating-point attribute (JSON `type: number`) — the built, readonly value
 * object the engine walks. Authors declare one with {@see make()}, which returns
 * a mutable {@see DecimalBuilder}; the resource **builds** it into this value
 * object before use. Serializes/hydrates as `float`.
 */
final readonly class Decimal extends AbstractFieldValue
{
    public static function make(string $name): DecimalBuilder
    {
        return new DecimalBuilder($name);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (\is_numeric($raw) ? (float) $raw : $raw);
    }

    protected function deserializeValue(mixed $value): mixed
    {
        return \is_numeric($value) ? (float) $value : $value;
    }
}
