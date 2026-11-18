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
    /**
     * Declares a to-one relation to `$type` held by the related model. `$foreignKey` names
     * the field or to-one relation of `$type` that holds the parent's id: the relation then
     * reads its target as the related row whose foreign key is the parent's id. Leave it
     * out to read the target the parent model holds.
     */
    public static function make(string $name, string $type, ?string $foreignKey = null): HasOneBuilder
    {
        return HasOneBuilder::make($name, $type, $foreignKey);
    }
}
