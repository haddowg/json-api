<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A to-one relationship backed by a foreign key on the **related** model
 * (`hasOne`) — the built, readonly value object the engine walks. Identical
 * metadata to {@see BelongsTo}; the distinction is for data-layer adapters (and the
 * lazy linkage default set on its {@see HasOneBuilder}). Authors declare one with
 * {@see make()}, which returns a mutable {@see HasOneBuilder}.
 */
final readonly class HasOne extends BelongsTo
{
    public static function make(string $name, string $type): HasOneBuilder
    {
        return HasOneBuilder::make($name, $type);
    }
}
