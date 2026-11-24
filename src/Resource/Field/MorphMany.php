<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The inverse of a polymorphic to-one: the related rows whose `MorphTo` points at the
 * parent, as Laravel's `morphMany` reads them. It builds the same {@see HasMany} as
 * `HasMany::make($name, $type, $morphTo)`, and warm-up refuses it unless `$morphTo` names
 * a `MorphTo` of the related type.
 *
 *     MorphMany::make('notes', 'notes', 'owner'),
 */
final class MorphMany
{
    private function __construct() {}

    public static function make(string $name, string $type, string $morphTo): HasManyBuilder
    {
        return HasManyBuilder::morphMany($name, $type, $morphTo);
    }
}
